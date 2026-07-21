<?php
/**
 * Lua scripts for atomic backend operations.
 *
 * The incr/decr script decodes the plugin's versioned value envelope, applies
 * an integer offset, clamps at zero for decrements, re-encodes, and writes
 * back while preserving the existing PTTL (including no-expiry). It returns
 * distinct result codes so missing keys, corrupt envelopes, and successful
 * updates are never conflated.
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

local function cmp_str(a, b)
    if #a ~= #b then
        return #a > #b and 1 or -1
    end
    if a == b then
        return 0
    end
    return a > b and 1 or -1
end

local function add_str(a, b)
    local res = {}
    local carry = 0
    local i = #a
    local j = #b
    while i > 0 or j > 0 or carry > 0 do
        local sum = carry
        if i > 0 then
            sum = sum + string.byte(a, i) - 48
            i = i - 1
        end
        if j > 0 then
            sum = sum + string.byte(b, j) - 48
            j = j - 1
        end
        carry = math.floor(sum / 10)
        table.insert(res, 1, string.char((sum % 10) + 48))
    end
    return table.concat(res)
end

local function sub_str(a, b)
    local res = {}
    local borrow = 0
    local i = #a
    local j = #b
    while i > 0 do
        local val = string.byte(a, i) - 48 - borrow
        i = i - 1
        local sub_val = 0
        if j > 0 then
            sub_val = string.byte(b, j) - 48
            j = j - 1
        end
        if val < sub_val then
            val = val + 10
            borrow = 1
        else
            borrow = 0
        end
        table.insert(res, 1, string.char((val - sub_val) + 48))
    end
    local s = table.concat(res)
    s = s:gsub("^0+", "")
    if s == "" then
        return "0"
    end
    return s
end

local function normalize_unsigned(value)
    value = value:gsub("^0+", "")
    if value == "" then
        return "0"
    end
    return value
end

local function trim(value)
    return value:match("^%s*(.-)%s*$")
end

local key = KEYS[1]
local offset_str = ARGV[1]
local is_negative = false
if string.sub(offset_str, 1, 1) == '-' then
    is_negative = true
    offset_str = string.sub(offset_str, 2)
end

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

if tag < 1 or tag > 6 then
    return {'CORRUPT'}
end

if tag == 1 and string.match(payload, "^%-?%d+$") == nil then
    return {'CORRUPT'}
end

if tag == 2 and string.len(payload) ~= 8 then
    return {'CORRUPT'}
end

if tag == 4 and (string.len(payload) ~= 1 or (payload ~= string.char(0) and payload ~= string.char(1))) then
    return {'CORRUPT'}
end

if tag == 5 and string.len(payload) ~= 0 then
    return {'CORRUPT'}
end

local string_value = tag == 3 and trim(payload) or ""
local string_is_integer = string.match(string_value, "^[%+%-]?%d+$") ~= nil
local string_is_decimal = string_value ~= ""
    and string.match(string_value, "^[%+%-%.%deE]+$") ~= nil
    and tonumber(string_value) ~= nil

local is_int_payload = (tag == 1) or (tag == 4) or (tag == 5) or (tag == 3 and string_is_integer)

local new_tag = 1
local new_payload = ''
local display_val = ''

if is_int_payload then
    local current_str = "0"
    local is_current_negative = false

    if tag == 1 or tag == 3 then
        local raw_payload = tag == 3 and string_value or payload
        if string.sub(raw_payload, 1, 1) == '-' then
            is_current_negative = true
            current_str = normalize_unsigned(string.sub(raw_payload, 2))
        else
            if string.sub(raw_payload, 1, 1) == '+' then
                raw_payload = string.sub(raw_payload, 2)
            end
            current_str = normalize_unsigned(raw_payload)
        end
    elseif tag == 4 then
        current_str = "0"
    end

    local new_val_str = ""
    if is_current_negative then
        if is_negative then
            new_val_str = "0"
        else
            if cmp_str(offset_str, current_str) <= 0 then
                new_val_str = "0"
            else
                new_val_str = sub_str(offset_str, current_str)
            end
        end
    else
        if not is_negative then
            new_val_str = add_str(current_str, offset_str)
        else
            if cmp_str(current_str, offset_str) <= 0 then
                new_val_str = "0"
            else
                new_val_str = sub_str(current_str, offset_str)
            end
        end
    end

    if #new_val_str > 19 or (#new_val_str == 19 and cmp_str(new_val_str, "9223372036854775807") > 0) then
        new_val_str = "9223372036854775807"
    end

    new_tag = 1
    new_payload = new_val_str
    display_val = new_val_str
else
    local current = 0
    if tag == 2 then
        current = decode_double(payload)
    elseif tag == 3 and string_is_decimal then
        current = tonumber(string_value) or 0
    end

    if current ~= current or current == math.huge or current == -math.huge then
        current = 0
    end

    if current < 0 then
        current = math.ceil(current)
    else
        current = math.floor(current)
    end

    local offset = tonumber(ARGV[1])
    local new_value = current + offset
    if new_value < 0 then
        new_value = 0
    end

    new_tag = 1
    if new_value >= 9223372036854775807 then
        new_payload = "9223372036854775807"
    else
        new_payload = string.format('%.0f', new_value)
    end
    display_val = new_payload
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

return {'OK', display_val}
LUA;

	private function __construct() {
	}
}
