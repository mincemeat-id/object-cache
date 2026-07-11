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
local key = KEYS[1]
local offset = tonumber(ARGV[1])

local raw = redis.call('GET', key)
if raw == false then
    return {'MISSING'}
end

-- Validate minimum length (4 magic + 1 version + 1 tag + 4 length = 10).
if string.len(raw) < 10 then
    return {'CORRUPT'}
end

-- Validate magic.
if string.sub(raw, 1, 4) ~= 'MCOC' then
    return {'CORRUPT'}
end

-- Validate version.
local ver = string.byte(raw, 5)
if ver ~= 1 then
    return {'CORRUPT'}
end

-- Validate tag is integer (tag 1).
local tag = string.byte(raw, 6)
if tag ~= 1 then
    return {'INVALID'}
end

-- Read the 4-byte big-endian length.
local b1 = string.byte(raw, 7)
local b2 = string.byte(raw, 8)
local b3 = string.byte(raw, 9)
local b4 = string.byte(raw, 10)
local length = b1 * 16777216 + b2 * 65536 + b3 * 256 + b4

-- Extract and validate the payload.
local payload = string.sub(raw, 11)
if string.len(payload) ~= length then
    return {'CORRUPT'}
end

if length == 0 then
    return {'CORRUPT'}
end

-- Validate the payload is a decimal integer string.
local abs_str = payload
if string.sub(payload, 1, 1) == '-' then
    abs_str = string.sub(payload, 2)
end

if abs_str == '' then
    return {'CORRUPT'}
end

-- Check every byte of abs_str is a digit.
local valid = true
for i = 1, string.len(abs_str) do
    local c = string.byte(abs_str, i)
    if c < 48 or c > 57 then
        valid = false
        break
    end
end
if not valid then
    return {'CORRUPT'}
end

-- Apply the offset.
local current = tonumber(payload)
local new_value = current + offset

-- Clamp at zero for decrements (WordPress behavior).
if new_value < 0 then
    new_value = 0
end

-- Re-encode the integer envelope: magic + version + tag + length + payload.
local new_payload = tostring(new_value)
local new_len = string.len(new_payload)
local new_raw = 'MCOC'
    .. string.char(1)
    .. string.char(1)
    .. string.char(math.floor(new_len / 16777216) % 256)
    .. string.char(math.floor(new_len / 65536) % 256)
    .. string.char(math.floor(new_len / 256) % 256)
    .. string.char(new_len % 256)
    .. new_payload

-- Preserve the existing PTTL (including no-expiry = -1).
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
