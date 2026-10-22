<?php

namespace App\Support\Passkeys;

use App\Models\Passkey;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Symfony\Component\Serializer\Encoder\JsonEncode;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\SerializerInterface;
use Throwable;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\CredentialRecord;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;

/**
 * The thin Laravel layer over web-auth/webauthn-lib (#9). The library does
 * all the cryptography and ceremony checks; this class decides policy:
 *
 * - The RP ID and the only allowed origin come from APP_URL, so staging and
 *   production passkeys are separate and a lookalike origin is rejected
 *   outright (no subdomain matching).
 * - User verification (Face ID, PIN) and discoverable credentials are
 *   required, because a passkey here replaces the email link rather than
 *   sitting alongside it as a second factor.
 * - Each challenge lives in the session and is pulled (single use) when
 *   it's checked.
 */
final class WebAuthn
{
    private const SESSION_REGISTRATION = 'passkeys.registration_options';

    private const SESSION_LOGIN = 'passkeys.login_options';

    private const TIMEOUT_MS = 120_000;

    /** COSE algorithms we accept: ES256 (every platform authenticator) and RS256 (Windows Hello). */
    private const ALGORITHMS = [-7, -257];

    private SerializerInterface $serializer;

    public function __construct()
    {
        $attestation = AttestationStatementSupportManager::create([NoneAttestationStatementSupport::create()]);
        $this->serializer = (new WebauthnSerializerFactory($attestation))->create();
    }

    /**
     * Start registering a passkey for a signed-in user. Returns the JSON for
     * navigator.credentials.create().
     */
    public function registrationOptions(User $user): string
    {
        $options = PublicKeyCredentialCreationOptions::create(
            rp: PublicKeyCredentialRpEntity::create('Offing', self::rpId()),
            user: PublicKeyCredentialUserEntity::create($user->email, self::userHandle($user), $user->name),
            challenge: random_bytes(32),
            pubKeyCredParams: array_map(fn (int $alg) => PublicKeyCredentialParameters::createPk($alg), self::ALGORITHMS),
            authenticatorSelection: AuthenticatorSelectionCriteria::create(
                userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
                residentKey: AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_REQUIRED,
            ),
            attestation: PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            // Stops an authenticator that already holds one of this user's
            // passkeys from creating a duplicate.
            excludeCredentials: $user->passkeys()->get()
                ->map(fn (Passkey $passkey) => $this->record($passkey)->getPublicKeyCredentialDescriptor())
                ->all(),
            timeout: self::TIMEOUT_MS,
        );

        $json = $this->toJson($options);
        Session::put(self::SESSION_REGISTRATION, $json);

        return $json;
    }

    /**
     * Finish registering. Returns the verified credential record, or null for
     * anything invalid: a malformed payload, the wrong challenge, origin or RP,
     * or no user verification.
     */
    public function verifyRegistration(string $credentialJson): ?CredentialRecord
    {
        $optionsJson = Session::pull(self::SESSION_REGISTRATION);
        if (! is_string($optionsJson)) {
            return null;
        }

        try {
            $options = $this->serializer->deserialize($optionsJson, PublicKeyCredentialCreationOptions::class, 'json');
            $credential = $this->serializer->deserialize($credentialJson, PublicKeyCredential::class, 'json');

            if (! $credential->response instanceof AuthenticatorAttestationResponse) {
                return null;
            }

            return AuthenticatorAttestationResponseValidator::create($this->ceremonies()->creationCeremony())
                ->check($credential->response, $options, self::rpId());
        } catch (Throwable $e) {
            Log::notice('Passkey registration rejected', ['reason' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Start a sign-in. No allowCredentials, so the browser offers any
     * discoverable passkey for this site, including through autofill
     * (conditional mediation).
     */
    public function loginOptions(): string
    {
        $options = PublicKeyCredentialRequestOptions::create(
            challenge: random_bytes(32),
            rpId: self::rpId(),
            userVerification: PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_REQUIRED,
            timeout: self::TIMEOUT_MS,
        );

        $json = $this->toJson($options);
        Session::put(self::SESSION_LOGIN, $json);

        return $json;
    }

    /**
     * Finish a sign-in. Returns the matching passkey with its counter and
     * backup state updated, or null if anything about the assertion is wrong.
     */
    public function verifyLogin(string $credentialJson): ?Passkey
    {
        $optionsJson = Session::pull(self::SESSION_LOGIN);
        if (! is_string($optionsJson)) {
            return null;
        }

        try {
            $options = $this->serializer->deserialize($optionsJson, PublicKeyCredentialRequestOptions::class, 'json');
            $credential = $this->serializer->deserialize($credentialJson, PublicKeyCredential::class, 'json');
            $response = $credential->response;

            if (! $response instanceof AuthenticatorAssertionResponse) {
                return null;
            }

            $passkey = Passkey::query()->where('credential_id', self::encodeId($credential->rawId))->first();
            if ($passkey === null) {
                return null;
            }

            $record = AuthenticatorAssertionResponseValidator::create($this->ceremonies()->requestCeremony())
                ->check($this->record($passkey), $response, $options, self::rpId(), $response->userHandle);
        } catch (Throwable $e) {
            Log::notice('Passkey sign-in rejected', ['reason' => $e->getMessage()]);

            return null;
        }

        $passkey->credential = $this->toJson($record);
        $passkey->last_used_at = now();
        $passkey->save();

        return $passkey;
    }

    public function serializeRecord(CredentialRecord $record): string
    {
        return $this->toJson($record);
    }

    public function record(Passkey $passkey): CredentialRecord
    {
        return $this->serializer->deserialize($passkey->credential, CredentialRecord::class, 'json');
    }

    /** base64url, no padding: how a credential ID is stored and looked up. */
    public static function encodeId(string $rawId): string
    {
        return rtrim(strtr(base64_encode($rawId), '+/', '-_'), '=');
    }

    /**
     * Passkeys belong to this environment's exact host: offing.uk in
     * production, the staging host on staging. Never a parent domain.
     */
    public static function rpId(): string
    {
        return (string) parse_url((string) config('app.url'), PHP_URL_HOST);
    }

    /**
     * The opaque per-user handle stored on the authenticator. Deterministic,
     * so every passkey a user makes shares it, and it carries no email or
     * database ID. An assertion is matched by credential ID; the handle is
     * only compared against the stored record, so rotating APP_KEY doesn't
     * break existing passkeys.
     */
    public static function userHandle(User $user): string
    {
        return hash_hmac('sha256', 'passkey-user-handle:'.$user->getKey(), (string) config('app.key'), true);
    }

    private function ceremonies(): CeremonyStepManagerFactory
    {
        $url = parse_url((string) config('app.url'));
        $origin = ($url['scheme'] ?? 'https').'://'.($url['host'] ?? '').(isset($url['port']) ? ':'.$url['port'] : '');

        $factory = new CeremonyStepManagerFactory;
        $factory->setAllowedOrigins([$origin]);
        // Plain http is only acceptable on localhost, for local development.
        $factory->setSecuredRelyingPartyId(['localhost']);

        return $factory;
    }

    private function toJson(object $value): string
    {
        return $this->serializer->serialize($value, 'json', [
            AbstractObjectNormalizer::SKIP_NULL_VALUES => true,
            JsonEncode::OPTIONS => JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        ]);
    }
}
