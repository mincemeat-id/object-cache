<?php
/**
 * Lua scripts for atomic backend operations.
 *
 * The incr/decr script decodes the plugin's versioned value envelope, applies
 * an integer offset, clamps at zero for decrements, re-encodes, and writes
 * back while preserving the existing PTTL (including no-expiry). It returns
 * distinct result codes so missing keys, invalid/non-integer values, and
 * successful updates are never conflated.
 *
 * @package Mincemeat\ObjectCache
 */

declare(strict_types=1);

namespace Mincemeat\ObjectCache;

/**
 * Holds the Lua source and result-code constants for atomic operations.
 *
 * @internal
 */
final class LuaScripts {

	/** Successful update; the new integer value follows this code. */
	public const RESULT_OK = 'OK';

	/** The key does not exist in the backend. */
	public const RESULT_MISSING = 'MISSING';

	/** The key exists but the value is not a supported numeric envelope. */
	public const RESULT_INVALID = 'INVALID';

	/** The envelope is corrupt or has an unknown version/tag. */
	public const RESULT_CORRUPT = 'CORRUPT';

	/**
	 * The increment/decrement Lua script.
	 *
	 * KEYS[1] = the item key.
	 * ARGV[1] = the signed integer offset (string, e.g. "1" or "-5").
	 *
	 * Returns:
	 *   {RESULT_OK,    <new_value>}  on success.
	 *   {RESULT_MISSING}             when the key is absent.
	 *   {RESULT_INVALID}             when the value is not an integer envelope.
	 *   {RESULT_CORRUPT}             when the envelope is malformed.
	 *
	 * The script uses only Redis/Valkey-compatible Lua and string/TTL
	 * commands: GET, PTTL, SET, UNLINK. It does not use KEYS, SCAN, or
	 * FLUSHDB. It preserves the existing PTTL (including no-expiry = -1)
	 * and never resets it.
	 */
	public const INCR_DECR = <<<'LUA'
local function decode_double(str)
    local b1, b2, b3, b4, b5, b6, b7, b8 = string.byte(str, 1, 8)
    local sign = 1
    if b8 >= 128 then
        sign = -1
        b8 = b8 - 128
    end
    local exponent = b8 * 16 + math.floor(b7 / 16)
    if exponent == 2047 then
        return 0
    end
    local mantissa = (b7 % 16) * 2^48
        + b6 * 2^40
        + b5 * 2^32
        + b4 * 2^24
        + b3 * 2^16
        + b2 * 2^8
        + b1
    if exponent == 0 then
        if mantissa == 0 then
            return 0
        else
            return sign * mantissa * 2^(-1022 - 52)
        end
    else
        return sign * (1 + mantissa / 2^52) * 2^(exponent - 1023)
    end
end

local function encode_double(value)
    if value == 0 then
        return string.char(0, 0, 0, 0, 0, 0, 0, 0)
    end
    local sign = 0
    if value < 0 then
        sign = 1
        value = -value
    end
    local exponent = math.floor(math.log(value) / math.log(2))
    local mantissa = value / 2^exponent
    if mantissa < 1 then
        mantissa = mantissa * 2
        exponent = exponent - 1
    elseif mantissa >= 2 then
        mantissa = mantissa / 2
        exponent = exponent + 1
    end
    exponent = exponent + 1023
    if exponent >= 2047 then
        if sign == 1 then
            return string.char(0, 0, 0, 0, 0, 0, 240, 255)
        else
            return string.char(0, 0, 0, 0, 0, 0, 240, 127)
        end
    elseif exponent <= 0 then
        return string.char(0, 0, 0, 0, 0, 0, 0, 0)
    end
    mantissa = (mantissa - 1) * 2^52
    local m_part = mantissa
    local bytes = {}
    for i = 1, 6 do
        local b = m_part % 256
        bytes[i] = math.floor(b)
        m_part = (m_part - b) / 256
    end
    local b7 = math.floor(m_part % 16) + (exponent % 16) * 16
    local b8 = math.floor(exponent / 16) + sign * 128
    return string.char(bytes[1], bytes[2], bytes[3], bytes[4], bytes[5], bytes[6], b7, b8)
end

local key = KEYS[1]
local offset = tonumber(ARGV[1])

local raw = redis.call('GET', key)
if raw == false then
    return {'MISSING'}
end

if string.len(raw) < 10 then
    return {'CORRUPT'}
end

if string.sub(raw, 1, 4) ~= 'MCOC' then
    return {'CORRUPT'}
end

local ver = string.byte(raw, 5)
if ver ~= 1 then
    return {'CORRUPT'}
end

local tag = string.byte(raw, 6)

local b1 = string.byte(raw, 7)
local b2 = string.byte(raw, 8)
local b3 = string.byte(raw, 9)
local b4 = string.byte(raw, 10)
local length = b1 * 16777216 + b2 * 65536 + b3 * 256 + b4

local payload = string.sub(raw, 11)
if string.len(payload) ~= length then
    return {'CORRUPT'}
end

local current = 0

if tag == 1 then
    current = tonumber(payload)
    if current == nil then
        return {'CORRUPT'}
    end
elseif tag == 2 then
    if string.len(payload) ~= 8 then
        return {'CORRUPT'}
    end
    current = decode_double(payload)
elseif tag == 3 then
    current = tonumber(payload)
    if current == nil then
        current = 0
    end
elseif tag == 4 then
    if payload == string.char(1) then
        current = 1
    else
        current = 0
    end
elseif tag == 5 then
    current = 0
else
    current = 0
end

local new_value = current + offset
if new_value < 0 then
    new_value = 0
end

local new_tag = 1
local new_payload = ''

if new_value % 1 == 0 then
    new_tag = 1
    new_payload = string.format('%.0f', new_value)
else
    new_tag = 2
    new_payload = encode_double(new_value)
end

local new_len = string.len(new_payload)
local new_raw = 'MCOC'
    .. string.char(1)
    .. string.char(new_tag)
    .. string.char(math.floor(new_len / 16777216) % 256)
    .. string.char(math.floor(new_len / 65536) % 256)
    .. string.char(math.floor(new_len / 256) % 256)
    .. string.char(new_len % 256)
    .. new_payload

local pttl = redis.call('PTTL', key)
if pttl == -1 then
    redis.call('SET', key, new_raw)
elseif pttl > 0 then
    redis.call('SET', key, new_raw, 'PX', pttl)
else
    return {'MISSING'}
end

return {'OK', tostring(new_value)}
LUA;

	private function __construct() {
	}
}
