<?php
/**
 * Unit tests for Redsys_Client — crypto functions (encrypt_3des, encrypt_key/decrypt_key
 * roundtrip) and constants.
 *
 * Pure functions, no WordPress needed beyond stubs in tests/bootstrap.php.
 */

namespace Convoca\Gateway\Tests;

use PHPUnit\Framework\TestCase;

class RedsysClientTest extends TestCase
{
    private function loadClass(): void
    {
        $path = dirname(__DIR__, 2) . '/includes/Redsys_Client.php';
        if (file_exists($path)) {
            require_once $path;
        }
    }

    protected function setUp(): void
    {
        $this->loadClass();
    }

    /* ── Reflection helpers ────────────────────────── */

    /**
     * Invoke a private static method via reflection.
     */
    private function invokeStatic(string $class, string $method, array $args = []): mixed
    {
        $ref    = new \ReflectionClass($class);
        $method = $ref->getMethod($method);
        return $method->invokeArgs(null, $args);
    }

    /**
     * Read a private constant via reflection.
     */
    private function getPrivateConst(string $class, string $name): mixed
    {
        $ref = new \ReflectionClass($class);
        $c   = $ref->getReflectionConstant($name);
        return $c ? $c->getValue() : null;
    }

    /* ── Constants ─────────────────────────────────── */

    public function test_public_constants(): void
    {
        $this->assertSame('978', \Convoca\Gateway\Redsys_Client::CURRENCY_EUR);
        $this->assertSame('0', \Convoca\Gateway\Redsys_Client::TXTYPE_AUTH);
        $this->assertSame('T', \Convoca\Gateway\Redsys_Client::METHOD_CARD);
        $this->assertSame('z', \Convoca\Gateway\Redsys_Client::METHOD_BIZUM);
    }

    public function test_private_constant_sig_version(): void
    {
        $this->assertSame(
            'HMAC_SHA256_V1',
            $this->getPrivateConst('Convoca\\Gateway\\Redsys_Client', 'SIG_VERSION')
        );
    }

    public function test_private_constant_url_test(): void
    {
        $this->assertSame(
            'https://sis-t.redsys.es:25443/sis/realizarPago',
            $this->getPrivateConst('Convoca\\Gateway\\Redsys_Client', 'URL_TEST')
        );
    }

    public function test_private_constant_url_prod(): void
    {
        $this->assertSame(
            'https://sis.redsys.es/sis/realizarPago',
            $this->getPrivateConst('Convoca\\Gateway\\Redsys_Client', 'URL_PROD')
        );
    }

    /* ── encrypt_3des ──────────────────────────────── */

    /**
     * Build a 24-byte key (3DES expects 24 bytes for three 8-byte sub-keys).
     */
    private function key3des(string $fill): string
    {
        return str_pad(substr($fill, 0, 24), 24, "\0");
    }

    public function test_encrypt_3des_exact_8_byte_block(): void
    {
        // exactly 8 bytes → no padding needed, output = 8 bytes
        $result = $this->invokeStatic(
            'Convoca\\Gateway\\Redsys_Client',
            'encrypt_3des',
            ['12345678', $this->key3des('A')]
        );

        $this->assertIsString($result);
        $this->assertSame(8, strlen($result));
    }

    public function test_encrypt_3des_padding_to_multiple_of_8(): void
    {
        $key = $this->key3des('B');

        // Input lengths that are NOT multiples of 8 should be padded.
        foreach ([1, 3, 5, 7, 9, 12, 15] as $len) {
            $data   = str_repeat('x', $len);
            $result = $this->invokeStatic(
                'Convoca\\Gateway\\Redsys_Client',
                'encrypt_3des',
                [$data, $key]
            );

            $expectedLen = (int) ceil($len / 8) * 8;
            $this->assertSame(
                $expectedLen,
                strlen($result),
                "Input length $len should produce {$expectedLen}-byte output"
            );
        }
    }

    public function test_encrypt_3des_empty_input_produces_empty(): void
    {
        // strlen(0) % 8 == 0 → padding condition is false.
        // openssl_encrypt with OPENSSL_NO_PADDING on empty data returns empty string.
        $result = $this->invokeStatic(
            'Convoca\\Gateway\\Redsys_Client',
            'encrypt_3des',
            ['', $this->key3des('C')]
        );

        $this->assertSame(0, strlen($result));
    }

    public function test_encrypt_3des_is_deterministic(): void
    {
        $data = 'test1234';  // exactly 8 bytes
        $key  = $this->key3des('D');

        $result1 = $this->invokeStatic(
            'Convoca\\Gateway\\Redsys_Client',
            'encrypt_3des',
            [$data, $key]
        );
        $result2 = $this->invokeStatic(
            'Convoca\\Gateway\\Redsys_Client',
            'encrypt_3des',
            [$data, $key]
        );

        $this->assertSame($result1, $result2, 'Same data + key → same ciphertext');
    }

    public function test_encrypt_3des_different_keys_produce_different_output(): void
    {
        $data  = 'test1234';
        $key1  = $this->key3des('E');
        $key2  = $this->key3des('F');

        $r1 = $this->invokeStatic(
            'Convoca\\Gateway\\Redsys_Client',
            'encrypt_3des',
            [$data, $key1]
        );
        $r2 = $this->invokeStatic(
            'Convoca\\Gateway\\Redsys_Client',
            'encrypt_3des',
            [$data, $key2]
        );

        $this->assertNotSame($r1, $r2);
    }

    public function test_encrypt_3des_can_be_decrypted(): void
    {
        // Verify 3DES-CBC zero-IV roundtrip: decrypting should recover padded plaintext.
        $data = 'Hello123';
        $key  = $this->key3des('G');

        $encrypted = $this->invokeStatic(
            'Convoca\\Gateway\\Redsys_Client',
            'encrypt_3des',
            [$data, $key]
        );

        // Decrypt manually with the same algorithm.
        $iv      = str_repeat("\0", 8);
        $padded  = openssl_decrypt(
            $encrypted,
            'des-ede3-cbc',
            $key,
            OPENSSL_RAW_DATA | OPENSSL_NO_PADDING,
            $iv
        );

        // Should be the original data + null padding
        $this->assertStringStartsWith($data, $padded);
        $this->assertSame(
            (int) ceil(strlen($data) / 8) * 8,
            strlen($padded)
        );
    }

    /* ── encrypt_key / decrypt_key roundtrip ───────── */

    protected function ensureAuthSalt(): void
    {
        if (!defined('AUTH_SALT')) {
            define('AUTH_SALT', 'test-auth-salt--32-bytes------!');
        }
    }

    public function test_encrypt_key_roundtrip(): void
    {
        $this->ensureAuthSalt();

        $original  = 'sq7H9xLp2mK4vR8wN3cF6tY1bQ5dJ0gA';
        $encrypted = \Convoca\Gateway\Redsys_Client::encrypt_key($original);

        $this->assertIsString($encrypted);
        $this->assertStringStartsWith('enc:', $encrypted);

        // Strip 'enc:' prefix — decrypt_key expects raw Base64 payload.
        $payload   = substr($encrypted, 4);
        $decrypted = $this->invokeStatic(
            'Convoca\\Gateway\\Redsys_Client',
            'decrypt_key',
            [$payload]
        );

        $this->assertNotFalse($decrypted);
        $this->assertSame($original, $decrypted);
    }

    public function test_encrypt_key_empty_value_returns_empty(): void
    {
        $this->ensureAuthSalt();

        $result = \Convoca\Gateway\Redsys_Client::encrypt_key('');
        $this->assertSame('', $result);
    }

    public function test_encrypt_key_produces_different_iv_each_time(): void
    {
        $this->ensureAuthSalt();

        $value   = 'some-secret-value';
        $result1 = \Convoca\Gateway\Redsys_Client::encrypt_key($value);
        $result2 = \Convoca\Gateway\Redsys_Client::encrypt_key($value);

        // Random IV → different ciphertexts.
        $this->assertNotSame($result1, $result2);
    }

    public function test_encrypt_key_roundtrip_unicode(): void
    {
        $this->ensureAuthSalt();

        $original  = 'clave-secreta-áéíóú-日本語-€';
        $encrypted = \Convoca\Gateway\Redsys_Client::encrypt_key($original);

        $payload   = substr($encrypted, 4);
        $decrypted = $this->invokeStatic(
            'Convoca\\Gateway\\Redsys_Client',
            'decrypt_key',
            [$payload]
        );

        $this->assertSame($original, $decrypted);
    }

    public function test_decrypt_key_returns_false_on_garbage(): void
    {
        $this->ensureAuthSalt();

        $result = $this->invokeStatic(
            'Convoca\\Gateway\\Redsys_Client',
            'decrypt_key',
            ['not-valid-base64!!!']
        );

        $this->assertFalse($result);
    }

    /* ── build_merchant_params ─────────────────────── */

    private function baseParams(): array
    {
        return [
            'order_id'     => '250601ABC123',
            'amount_cents' => 3000,
            'product_desc' => 'Test Product',
            'pay_methods'  => '',
            'url_ok'       => 'https://example.com/ok',
            'url_ko'       => 'https://example.com/ko',
            'url_notify'   => 'https://example.com/notify',
            'is_bizum'     => false,
        ];
    }

    /**
     * Helper: build params and decode the Base64→JSON result.
     */
    private function decodeParams(array $overrides = []): array
    {
        $params = array_merge($this->baseParams(), $overrides);
        $b64    = \Convoca\Gateway\Redsys_Client::build_merchant_params($params);

        $raw = base64_decode($b64, true);
        $this->assertNotFalse($raw, 'Result should be valid Base64');

        $json = json_decode($raw, true);
        $this->assertIsArray($json, 'Decoded result should be a JSON array');

        return $json;
    }

    public function test_build_merchant_params_json_structure(): void
    {
        $json = $this->decodeParams();

        $this->assertSame('3000', $json['DS_MERCHANT_AMOUNT']);
        $this->assertSame('250601ABC123', $json['DS_MERCHANT_ORDER']);
        $this->assertSame('978', $json['DS_MERCHANT_CURRENCY']);
        $this->assertSame('0', $json['DS_MERCHANT_TRANSACTIONTYPE']);
        $this->assertSame('Test Product', $json['DS_MERCHANT_PRODUCTDESCRIPTION']);
        $this->assertSame('001', $json['DS_MERCHANT_TERMINAL']);
        $this->assertArrayHasKey('DS_MERCHANT_MERCHANTCODE', $json);
        $this->assertArrayHasKey('DS_MERCHANT_MERCHANTURL', $json);
        $this->assertArrayHasKey('DS_MERCHANT_URLOK', $json);
        $this->assertArrayHasKey('DS_MERCHANT_URLKO', $json);
    }

    public function test_build_merchant_params_includes_paymethods_when_set(): void
    {
        $json = $this->decodeParams(['pay_methods' => 'T,z']);

        $this->assertArrayHasKey('DS_MERCHANT_PAYMETHODS', $json);
        $this->assertSame('T,z', $json['DS_MERCHANT_PAYMETHODS']);
    }

    public function test_build_merchant_params_omits_paymethods_when_empty(): void
    {
        $json = $this->decodeParams(['pay_methods' => '']);

        $this->assertArrayNotHasKey('DS_MERCHANT_PAYMETHODS', $json);
    }

    public function test_build_merchant_params_truncates_long_description(): void
    {
        $json = $this->decodeParams(['product_desc' => str_repeat('A', 200)]);

        $this->assertSame(125, mb_strlen($json['DS_MERCHANT_PRODUCTDESCRIPTION']));
    }

    public function test_build_merchant_params_tokenize_adds_extra_fields(): void
    {
        $json = $this->decodeParams(['tokenize' => true, 'pay_methods' => 'T']);

        $this->assertSame('REQUIRED', $json['DS_MERCHANT_IDENTIFIER']);
        $this->assertSame('true', $json['DS_MERCHANT_DIRECTPAYMENT']);
    }

    public function test_build_merchant_params_omits_tokenize_fields_when_false(): void
    {
        $json = $this->decodeParams(['tokenize' => false]);

        $this->assertArrayNotHasKey('DS_MERCHANT_IDENTIFIER', $json);
        $this->assertArrayNotHasKey('DS_MERCHANT_DIRECTPAYMENT', $json);
    }

    public function test_build_merchant_params_amount_is_string(): void
    {
        $json = $this->decodeParams(['amount_cents' => 100]);

        $this->assertIsString($json['DS_MERCHANT_AMOUNT']);
        $this->assertSame('100', $json['DS_MERCHANT_AMOUNT']);
    }
}
