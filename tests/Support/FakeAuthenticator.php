<?php

namespace Tests\Support;

use CBOR\ByteStringObject;
use CBOR\MapObject;
use CBOR\NegativeIntegerObject;
use CBOR\TextStringObject;
use CBOR\UnsignedIntegerObject;
use OpenSSLAsymmetricKey;

/**
 * A software passkey for tests: one P-256 (ES256) key pair that answers
 * real WebAuthn ceremonies with byte-accurate CBOR, authenticator data
 * and signatures, so the server-side checks run unmodified. Knobs exist
 * to break each property the server must enforce (origin, RP, user
 * verification, challenge, counter).
 */
final class FakeAuthenticator
{
    public string $credentialId;

    public int $signCount = 0;

    private OpenSSLAsymmetricKey $key;

    private ?string $userHandle = null;

    public function __construct(
        public string $origin = 'http://localhost',
        public ?string $rpId = null,
    ) {
        $this->credentialId = random_bytes(16);
        $this->key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    }

    /**
     * Answer navigator.credentials.create() options (as the server's JSON).
     *
     * @param  array{uv?: bool, origin?: string, rpId?: string, challenge?: string}  $override
     */
    public function register(string $optionsJson, array $override = []): string
    {
        $options = json_decode($optionsJson, true, flags: JSON_THROW_ON_ERROR);
        $this->userHandle = self::b64d($options['user']['id']);
        $rpId = $override['rpId'] ?? $this->rpId ?? $options['rp']['id'];

        $clientData = $this->clientData('webauthn.create', $override['challenge'] ?? $options['challenge'], $override['origin'] ?? $this->origin);

        $details = openssl_pkey_get_details($this->key)['ec'];
        $coseKey = MapObject::create()
            ->add(UnsignedIntegerObject::create(1), UnsignedIntegerObject::create(2))   // kty: EC2
            ->add(UnsignedIntegerObject::create(3), NegativeIntegerObject::create(-7))  // alg: ES256
            ->add(NegativeIntegerObject::create(-1), UnsignedIntegerObject::create(1))  // crv: P-256
            ->add(NegativeIntegerObject::create(-2), ByteStringObject::create(str_pad($details['x'], 32, "\0", STR_PAD_LEFT)))
            ->add(NegativeIntegerObject::create(-3), ByteStringObject::create(str_pad($details['y'], 32, "\0", STR_PAD_LEFT)));

        $attestedCredentialData = str_repeat("\0", 16)                      // AAGUID
            .pack('n', strlen($this->credentialId)).$this->credentialId
            .(string) $coseKey;

        $authData = $this->authData($rpId, $override['uv'] ?? true, attested: true).$attestedCredentialData;

        $attestationObject = MapObject::create()
            ->add(TextStringObject::create('fmt'), TextStringObject::create('none'))
            ->add(TextStringObject::create('attStmt'), MapObject::create())
            ->add(TextStringObject::create('authData'), ByteStringObject::create($authData));

        return json_encode([
            'id' => self::b64($this->credentialId),
            'rawId' => self::b64($this->credentialId),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => self::b64($clientData),
                'attestationObject' => self::b64((string) $attestationObject),
                'transports' => ['internal', 'hybrid'],
            ],
            'clientExtensionResults' => new \stdClass,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Answer navigator.credentials.get() options.
     *
     * @param  array{uv?: bool, origin?: string, rpId?: string, challenge?: string, userHandle?: string|null}  $override
     */
    public function assert(string $optionsJson, array $override = []): string
    {
        $options = json_decode($optionsJson, true, flags: JSON_THROW_ON_ERROR);
        $rpId = $override['rpId'] ?? $this->rpId ?? $options['rpId'];

        $clientData = $this->clientData('webauthn.get', $override['challenge'] ?? $options['challenge'], $override['origin'] ?? $this->origin);
        $this->signCount++;
        $authData = $this->authData($rpId, $override['uv'] ?? true, attested: false);

        openssl_sign($authData.hash('sha256', $clientData, true), $signature, $this->key, OPENSSL_ALGO_SHA256);

        $userHandle = array_key_exists('userHandle', $override) ? $override['userHandle'] : $this->userHandle;

        return json_encode([
            'id' => self::b64($this->credentialId),
            'rawId' => self::b64($this->credentialId),
            'type' => 'public-key',
            'response' => array_filter([
                'clientDataJSON' => self::b64($clientData),
                'authenticatorData' => self::b64($authData),
                'signature' => self::b64($signature),
                'userHandle' => $userHandle === null ? null : self::b64($userHandle),
            ]),
            'clientExtensionResults' => new \stdClass,
        ], JSON_THROW_ON_ERROR);
    }

    private function clientData(string $type, string $challenge, string $origin): string
    {
        return json_encode(['type' => $type, 'challenge' => $challenge, 'origin' => $origin, 'crossOrigin' => false], JSON_UNESCAPED_SLASHES);
    }

    private function authData(string $rpId, bool $uv, bool $attested): string
    {
        // UP | UV? | BE | BS | AT?: a synced platform passkey (iCloud Keychain-style).
        $flags = 0x01 | ($uv ? 0x04 : 0) | 0x08 | 0x10 | ($attested ? 0x40 : 0);

        return hash('sha256', $rpId, true).chr($flags).pack('N', $this->signCount);
    }

    public static function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function b64d(string $value): string
    {
        return base64_decode(strtr($value, '-_', '+/'));
    }
}
