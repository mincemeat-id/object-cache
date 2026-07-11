<?php
/**
 * Unit tests for ValueCodec: round-trip every supported type, fixture
 * stability, corruption-as-miss, and branch coverage for decode failure paths.
 *
 * @package Mincemeat\ObjectCache
 * @group unit
 */

declare(strict_types=1);

namespace Mincemeat\ObjectCache\Tests\Unit;

use Mincemeat\ObjectCache\ValueCodec;
use Mincemeat\ObjectCache\ValueCodecException;
use PHPUnit\Framework\TestCase;
use stdClass;

class ValueCodecTest extends TestCase
{
    /* ----------------------------------------------------------------
     * Round-trip success cases
     * ---------------------------------------------------------------- */

    /**
     * @dataProvider roundtrip_scalar
     */
    public function test_roundtrip_scalar($value)
    {
        $encoded = ValueCodec::encode($value);
        [$found, $decoded, $error] = ValueCodec::decode($encoded);

        $this->assertTrue($found, 'Decode should succeed');
        $this->assertNull($error, 'No error category');
        $this->assertSame($value, $decoded);
    }

    public function roundtrip_scalar(): array
    {
        return array(
            'null'            => array(null),
            'true'            => array(true),
            'false'           => array(false),
            'int zero'        => array(0),
            'int positive'    => array(42),
            'int negative'    => array(-100),
            'int max'         => array(PHP_INT_MAX),
            'int min'         => array(PHP_INT_MIN),
            'float zero'       => array(0.0),
            'float positive'  => array(3.14),
            'float negative'   => array(-2.71),
            'float tiny'       => array(1.0E-10),
            'float big'        => array(1.0E20),
            'string empty'     => array(''),
            'string ascii'     => array('hello'),
            'string numeric'   => array('42'),
            'string zero'      => array('0'),
            'string false'     => array('false'),
            'string binary'    => array("\x00\x01\x02\xFF\xFE\x00"),
            'string unicode'   => array("caf\xc3\xa9 \xe6\x97\xa5\xe6\x9c\xac\xe8\xaa\x9e"),
            'string long'     => array(str_repeat('A', 100000)),
        );
    }

    public function test_roundtrip_null_envelope_uses_tag_null()
    {
        $encoded = ValueCodec::encode(null);

        $this->assertSame('MCOC', substr($encoded, 0, 4));
        $this->assertSame(ValueCodec::VERSION, ord($encoded[4]));
        $this->assertSame(ValueCodec::TAG_NULL, ord($encoded[5]));
    }

    public function test_roundtrip_bool_envelope_uses_tag_bool()
    {
        $true      = ValueCodec::encode(true);
        $false     = ValueCodec::encode(false);

        $this->assertSame(ValueCodec::TAG_BOOL, ord($true[5]));
        $this->assertSame(ValueCodec::TAG_BOOL, ord($false[5]));

        [$found_t, $val_t, $err_t] = ValueCodec::decode($true);
        [$found_f, $val_f, $err_f] = ValueCodec::decode($false);

        $this->assertTrue($found_t && $val_t === true);
        $this->assertTrue($found_f && $val_f === false);
        $this->assertNull($err_t);
        $this->assertNull($err_f);
    }

    public function test_roundtrip_integer_envelope_uses_tag_int_and_preserves_decimal_string()
    {
        $encoded = ValueCodec::encode(42);

        $this->assertSame(ValueCodec::TAG_INT, ord($encoded[5]));
        // Payload is the decimal string "42".
        $this->assertSame('42', substr($encoded, 10));

        [$found, $val, $err] = ValueCodec::decode($encoded);
        $this->assertTrue($found);
        $this->assertSame(42, $val);
        $this->assertIsInt($val);
        $this->assertNull($err);
    }

    public function test_numeric_string_is_stored_as_string_not_int()
    {
        $encoded = ValueCodec::encode('42');
        $this->assertSame(ValueCodec::TAG_STRING, ord($encoded[5]));

        [$found, $val, $err] = ValueCodec::decode($encoded);
        $this->assertTrue($found);
        $this->assertSame('42', $val);
        $this->assertIsString($val);
    }

    public function test_string_zero_is_not_int_zero()
    {
        $encoded = ValueCodec::encode('0');
        $this->assertSame(ValueCodec::TAG_STRING, ord($encoded[5]));

        [$found, $val] = ValueCodec::decode($encoded);
        $this->assertSame('0', $val);
    }

    /* ----------------------------------------------------------------
     * Arrays and objects (TAG_SERIALIZED)
     * ---------------------------------------------------------------- */

    public function test_roundtrip_array()
    {
        $value = array('a' => 1, 'b' => 'two', 'c' => array(1, 2, 3), 'd' => null);

        $encoded = ValueCodec::encode($value);
        $this->assertSame(ValueCodec::TAG_SERIALIZED, ord($encoded[5]));

        [$found, $decoded, $err] = ValueCodec::decode($encoded);

        $this->assertTrue($found);
        $this->assertNull($err);
        $this->assertEquals($value, $decoded);
    }

    public function test_roundtrip_nested_object()
    {
        $obj           = new stdClass();
        $obj->name     = 'test';
        $obj->nested   = new stdClass();
        $obj->nested->v = 42;
        $obj->arr      = array(1, 2, 3);

        $encoded = ValueCodec::encode($obj);

        [$found, $decoded, $err] = ValueCodec::decode($encoded);

        $this->assertTrue($found);
        $this->assertNull($err);
        $this->assertInstanceOf(stdClass::class, $decoded);
        $this->assertSame('test', $decoded->name);
        $this->assertSame(42, $decoded->nested->v);
        $this->assertSame(array(1, 2, 3), $decoded->arr);
    }

    public function test_decode_serialized_unknown_class_becomes_incomplete_class()
    {
        // Unserializing an object whose class does not exist yields a
        // __PHP_Incomplete_Class instance. The codec must round-trip the
        // payload without a fatal and report a hit.
        $payload = 'O:3:"Foo":1:{s:1:"x";i:5;}';
        $encoded = ValueCodec::header_inline(ValueCodec::TAG_SERIALIZED, $payload);

        [$found, $decoded, $err] = ValueCodec::decode($encoded);

        $this->assertTrue($found);
        $this->assertNull($err);
        $this->assertInstanceOf('__PHP_Incomplete_Class', $decoded);
    }

    /* ----------------------------------------------------------------
     * Encode failures (exceptions)
     * ---------------------------------------------------------------- */

    public function test_encode_resource_throws()
    {
        $resource = fopen('php://memory', 'r');

        try {
            ValueCodec::encode($resource);
            $this->fail('Expected ValueCodecException');
        } catch (ValueCodecException $e) {
            $this->assertSame('encode-unsupported', $e->category());
        } finally {
            fclose($resource);
        }
    }

    public function test_encode_unsupported_type_throws()
    {
        // Closure is an object but not serializable.
        try {
            ValueCodec::encode(static function () {
            });
            $this->fail('Expected ValueCodecException');
        } catch (ValueCodecException $e) {
            $this->assertSame('encode-serialize-failed', $e->category());
        }
    }

    /* ----------------------------------------------------------------
     * Decode failures (corruption-as-miss, never fatal)
     * ---------------------------------------------------------------- */

    public function test_decode_empty_returns_miss()
    {
        [$found, $val, $err] = ValueCodec::decode('');

        $this->assertFalse($found);
        $this->assertFalse($val);
        $this->assertSame('decode-empty', $err);
    }

    public function test_decode_truncated_returns_miss()
    {
        // Magic + version but too short for a full header.
        [$found, $val, $err] = ValueCodec::decode('MCOC' . chr(1) . chr(1));

        $this->assertFalse($found);
        $this->assertSame('decode-truncated', $err);
    }

    public function test_decode_wrong_magic_returns_miss()
    {
        $bad = 'XXXX' . chr(1) . chr(0) . pack('N', 0);

        [$found, $val, $err] = ValueCodec::decode($bad);

        $this->assertFalse($found);
        $this->assertSame('decode-magic', $err);
    }

    public function test_decode_unknown_version_returns_miss()
    {
        $bad = 'MCOC' . chr(99) . chr(0) . pack('N', 0);

        [$found, $val, $err] = ValueCodec::decode($bad);

        $this->assertFalse($found);
        $this->assertSame('decode-version', $err);
    }

    public function test_decode_length_mismatch_returns_miss()
    {
        $encoded = ValueCodec::encode('hello');
        // Corrupt the length field to claim 999 bytes.
        $bad = substr($encoded, 0, 6) . pack('N', 999) . substr($encoded, 10);

        [$found, $val, $err] = ValueCodec::decode($bad);

        $this->assertFalse($found);
        $this->assertSame('decode-length', $err);
    }

    public function test_decode_unknown_tag_returns_miss()
    {
        // Magic+version+unknown_tag+length=0.
        $bad = 'MCOC' . chr(1) . chr(0xFF) . pack('N', 0);

        [$found, $val, $err] = ValueCodec::decode($bad);

        $this->assertFalse($found);
        $this->assertSame('decode-unknown-tag', $err);
    }

    public function test_decode_null_with_payload_returns_miss()
    {
        $bad = 'MCOC' . chr(ValueCodec::VERSION) . chr(ValueCodec::TAG_NULL) . pack('N', 5) . 'extra';

        [$found, $val, $err] = ValueCodec::decode($bad);

        $this->assertFalse($found);
        $this->assertSame('decode-null-payload', $err);
    }

    public function test_decode_bool_wrong_length_returns_miss()
    {
        $bad = 'MCOC' . chr(ValueCodec::VERSION) . chr(ValueCodec::TAG_BOOL) . pack('N', 2) . "\x01\x00";

        [$found, $val, $err] = ValueCodec::decode($bad);

        $this->assertFalse($found);
        $this->assertSame('decode-bool-payload', $err);
    }

    public function test_decode_int_invalid_payload_returns_miss()
    {
        // Tag INT, length=3, payload "abc".
        $bad = 'MCOC' . chr(ValueCodec::VERSION) . chr(ValueCodec::TAG_INT) . pack('N', 3) . 'abc';

        [$found, $val, $err] = ValueCodec::decode($bad);

        $this->assertFalse($found);
        $this->assertSame('decode-int-invalid', $err);
    }

    public function test_decode_int_empty_payload_returns_miss()
    {
        $bad = 'MCOC' . chr(ValueCodec::VERSION) . chr(ValueCodec::TAG_INT) . pack('N', 0) . '';

        [$found, $val, $err] = ValueCodec::decode($bad);

        $this->assertFalse($found);
        $this->assertSame('decode-int-empty', $err);
    }

    public function test_decode_double_wrong_length_returns_miss()
    {
        // Tag DOUBLE, length=4, payload "abcd".
        $bad = 'MCOC' . chr(ValueCodec::VERSION) . chr(ValueCodec::TAG_DOUBLE) . pack('N', 4) . 'abcd';

        [$found, $val, $err] = ValueCodec::decode($bad);

        $this->assertFalse($found);
        $this->assertSame('decode-double-length', $err);
    }

    public function test_decode_empty_serialized_returns_miss()
    {
        $bad = 'MCOC' . chr(ValueCodec::VERSION) . chr(ValueCodec::TAG_SERIALIZED) . pack('N', 0) . '';

        [$found, $val, $err] = ValueCodec::decode($bad);

        $this->assertFalse($found);
        $this->assertSame('decode-serialized-empty', $err);
    }

    public function test_decode_corrupt_serialized_returns_miss()
    {
        $bad = 'MCOC' . chr(ValueCodec::VERSION) . chr(ValueCodec::TAG_SERIALIZED) . pack('N', 8) . 'garbage!';

        [$found, $val, $err] = ValueCodec::decode($bad);

        $this->assertFalse($found);
        $this->assertSame('decode-serialized-failed', $err);
    }

    public function test_decode_serialized_false_is_distinguished_from_failure()
    {
        // TRUE serialization of `false` is 'b:0;'.
        $payload = 'b:0;';
        $encoded = ValueCodec::header_inline(ValueCodec::TAG_SERIALIZED, $payload);

        [$found, $val, $err] = ValueCodec::decode($encoded);

        $this->assertTrue($found);
        $this->assertFalse($val);
        $this->assertNull($err);
    }

    /* ----------------------------------------------------------------
     * Fixture stability across "process requests"
     * ---------------------------------------------------------------- */

    public function test_fixture_bytes_match_expected_layout()
    {
        // Verify our documented header layout is fixed:
        //   bytes 0-3  : magic "MCOC"
        //   byte  4     : version 0x01
        //   byte  5     : type tag
        //   bytes 6-9  : big-endian 32-bit length
        $encoded = ValueCodec::encode('x');

        $this->assertSame('MCOC', substr($encoded, 0, 4));
        $this->assertSame(0x01, ord($encoded[4]));
        $this->assertSame(ValueCodec::TAG_STRING, ord($encoded[5]));
        $this->assertSame(1, unpack('N', substr($encoded, 6, 4))[1]);
        $this->assertSame('x', substr($encoded, 10));
    }
}