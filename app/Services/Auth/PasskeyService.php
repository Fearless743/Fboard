<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Models\UserPasskey;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Illuminate\Support\Facades\Cache;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Symfony\Component\Uid\Uuid;
use Webauthn\AttestationStatement\AndroidKeyAttestationStatementSupport;
use Webauthn\AttestationStatement\AppleAttestationStatementSupport;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\FidoU2FAttestationStatementSupport;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorAssertionResponseValidator;

class PasskeyService
{
    public const TICKET_TTL = 300;

    /**
     * 从 app_url 解析 Rp ID（host 部分），解析失败时回退到 app.url。
     */
    public function rpId(): string
    {
        $appUrl = (string) admin_setting('app_url', '');
        if ($appUrl !== '') {
            $host = parse_url($appUrl, PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                return strtolower($host);
            }
        }
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);
        if (is_string($host) && $host !== '') {
            return strtolower($host);
        }
        return 'localhost';
    }

    public function rpName(): string
    {
        $name = (string) admin_setting('app_name', 'Fboard');
        return $name !== '' ? $name : 'Fboard';
    }

    /**
     * 计算合法 origin 列表：app_url origin + 当前请求 origin（host 与 rpId 一致时）。
     */
    public function allowedOrigins(?string $requestOrigin = null): array
    {
        $origins = [];
        $appUrl = (string) admin_setting('app_url', '');
        if ($appUrl !== '') {
            $origins[] = $this->normalizeOrigin($appUrl);
        }
        if ($requestOrigin) {
            $normalized = $this->normalizeOrigin($requestOrigin);
            if ($normalized && !in_array($normalized, $origins, true)) {
                $host = parse_url($normalized, PHP_URL_HOST);
                $rpId = $this->rpId();
                if (is_string($host) && ($host === $rpId || str_ends_with($host, '.' . $rpId) || $this->isLocalHost($host))) {
                    $origins[] = $normalized;
                }
            }
        }
        return array_values(array_filter($origins));
    }

    private function normalizeOrigin(string $urlOrOrigin): ?string
    {
        $parts = parse_url(trim($urlOrOrigin));
        if (!$parts || !isset($parts['host'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme'] ?? 'https');
        $host = strtolower($parts['host']);
        $port = $parts['port'] ?? null;
        if (($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80)) {
            $port = null;
        }
        return $scheme . '://' . $host . ($port ? ':' . $port : '');
    }

    private function isLocalHost(string $host): bool
    {
        return in_array($host, ['localhost', '127.0.0.1', '[::1]', '::1'], true);
    }

    private function ceremonyFactory(array $origins): CeremonyStepManagerFactory
    {
        $factory = new CeremonyStepManagerFactory();
        $secured = [];
        foreach (['localhost', '127.0.0.1', '[::1]', '::1'] as $h) {
            $secured[] = $h;
        }
        $factory->setSecuredRelyingPartyId($secured);
        if ($origins !== []) {
            $factory->setAllowedOrigins($origins);
        }
        $factory->setAttestationStatementSupportManager(
            new AttestationStatementSupportManager([
                new NoneAttestationStatementSupport(),
                new AndroidKeyAttestationStatementSupport(),
                new AppleAttestationStatementSupport(),
                new FidoU2FAttestationStatementSupport(),
            ])
        );
        return $factory;
    }

    private function serializer(): \Symfony\Component\Serializer\Serializer
    {
        $manager = new AttestationStatementSupportManager([
            new NoneAttestationStatementSupport(),
            new AndroidKeyAttestationStatementSupport(),
            new AppleAttestationStatementSupport(),
            new FidoU2FAttestationStatementSupport(),
        ]);
        $serializer = (new WebauthnSerializerFactory($manager))->create();
        assert($serializer instanceof \Symfony\Component\Serializer\Serializer);
        return $serializer;
    }

    public function hasPasskey(User $user): bool
    {
        return $user->passkeys()->exists();
    }

    /**
     * 生成注册 options（返回给 @simplewebauthn/browser 的 startRegistration 格式）。
     */
    public function registrationOptions(User $user, ?string $requestOrigin = null): array
    {
        $rpId = $this->rpId();
        $origins = $this->allowedOrigins($requestOrigin);
        $challenge = random_bytes(32);

        $rp = PublicKeyCredentialRpEntity::create($this->rpName(), $rpId);
        $userEntity = PublicKeyCredentialUserEntity::create(
            $user->email,
            Base64UrlSafe::encodeUnpadded((string) $user->id),
            $user->email
        );

        $exclude = UserPasskey::where('user_id', $user->id)->get()->map(
            fn (UserPasskey $p) => PublicKeyCredentialDescriptor::create('public-key', Base64UrlSafe::decodeNoPadding($p->credential_id), $p->transports ?? [])
        )->all();

        $options = PublicKeyCredentialCreationOptions::create(
            $rp,
            $userEntity,
            Base64UrlSafe::encodeUnpadded($challenge),
            [
                PublicKeyCredentialParameters::create('public-key', -7),
                PublicKeyCredentialParameters::create('public-key', -257),
            ],
            AuthenticatorSelectionCriteria::create(
                null,
                AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_PREFERRED,
                AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_PREFERRED
            ),
            PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            $exclude,
            60000
        );

        Cache::put(
            CacheKey::get('PASSKEY_REG_CHALLENGE', $user->id),
            [
                'challenge' => Base64UrlSafe::encodeUnpadded($challenge),
                'rp_id' => $rpId,
                'origins' => $origins,
                'user_id' => $user->id,
            ],
            self::TICKET_TTL
        );

        return [
            'rp' => ['name' => $this->rpName(), 'id' => $rpId],
            'user' => [
                'id' => Base64UrlSafe::encodeUnpadded((string) $user->id),
                'name' => $user->email,
                'displayName' => $user->email,
            ],
            'challenge' => Base64UrlSafe::encodeUnpadded($challenge),
            'pubKeyCredParams' => [
                ['type' => 'public-key', 'alg' => -7],
                ['type' => 'public-key', 'alg' => -257],
            ],
            'authenticatorSelection' => [
                'userVerification' => 'preferred',
                'residentKey' => 'preferred',
                'requireResidentKey' => false,
            ],
            'attestation' => 'none',
            'excludeCredentials' => array_map(
                fn (PublicKeyCredentialDescriptor $d) => [
                    'type' => $d->type,
                    'id' => Base64UrlSafe::encodeUnpadded($d->id),
                    'transports' => $d->transports,
                ],
                $exclude
            ),
            'timeout' => 60000,
        ];
    }

    /**
     * 校验注册响应并落库。
     */
    public function verifyRegistration(User $user, array $credentialJson, ?string $requestOrigin = null, ?string $name = null): UserPasskey
    {
        $stored = Cache::get(CacheKey::get('PASSKEY_REG_CHALLENGE', $user->id));
        if (!$stored || !isset($stored['challenge'])) {
            abort(400, __('Passkey registration session expired, please retry'));
        }
        $origins = $this->allowedOrigins($requestOrigin);
        if (empty($origins) && !empty($stored['origins'])) {
            $origins = $stored['origins'];
        }

        $serializer = $this->serializer();
        /** @var PublicKeyCredential $credential */
        $credential = $serializer->denormalize($credentialJson, PublicKeyCredential::class, 'json');
        $response = $credential->response;
        if (!$response instanceof AuthenticatorAttestationResponse) {
            abort(400, __('Invalid passkey response'));
        }

        $rpId = $stored['rp_id'] ?? $this->rpId();
        $rp = PublicKeyCredentialRpEntity::create($this->rpName(), $rpId);
        $userEntity = PublicKeyCredentialUserEntity::create(
            $user->email,
            Base64UrlSafe::encodeUnpadded((string) $user->id),
            $user->email
        );
        $creationOptions = PublicKeyCredentialCreationOptions::create(
            $rp,
            $userEntity,
            $stored['challenge'],
            [
                PublicKeyCredentialParameters::create('public-key', -7),
                PublicKeyCredentialParameters::create('public-key', -257),
            ],
            null,
            PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE
        );

        $factory = $this->ceremonyFactory($origins);
        $validator = AuthenticatorAttestationResponseValidator::create($factory->creationCeremony());
        $record = $validator->check($response, $creationOptions, $rpId);

        $credentialId = Base64UrlSafe::encodeUnpadded($record->publicKeyCredentialId);
        if (UserPasskey::where('credential_id', $credentialId)->exists()) {
            abort(400, __('This passkey is already registered'));
        }

        Cache::forget(CacheKey::get('PASSKEY_REG_CHALLENGE', $user->id));

        $passkey = new UserPasskey();
        $passkey->user_id = $user->id;
        $passkey->name = mb_substr(trim((string) ($name ?? '')), 0, 64) ?: ('Passkey ' . date('Y-m-d H:i'));
        $passkey->credential_id = $credentialId;
        $passkey->type = $record->type;
        $passkey->transports = $record->transports;
        $passkey->attestation_type = $record->attestationType;
        $passkey->aaguid = $record->aaguid->toRfc4122();
        $passkey->credential_public_key = base64_encode($record->credentialPublicKey);
        $passkey->counter = $record->counter;
        $passkey->backup_eligible = (bool) $record->backupEligible;
        $passkey->backup_status = (bool) $record->backupStatus;
        $passkey->last_used_at = time();
        $passkey->save();

        return $passkey;
    }

    /**
     * 为一次性票据生成登录 options。票据由密码登录第一步签发。
     */
    public function authenticationOptionsForTicket(string $ticket, ?string $requestOrigin = null): array
    {
        $userId = Cache::get(CacheKey::get('PASSKEY_LOGIN_TICKET', $ticket));
        if (!$userId) {
            abort(400, __('Login session expired, please login again'));
        }
        $user = User::find($userId);
        if (!$user || $user->banned) {
            abort(400, __('User not found'));
        }
        return $this->authenticationOptions($user, $ticket, $requestOrigin);
    }

    public function authenticationOptions(User $user, ?string $ticket = null, ?string $requestOrigin = null): array
    {
        $rpId = $this->rpId();
        $origins = $this->allowedOrigins($requestOrigin);
        $challenge = random_bytes(32);
        $challengeB64 = Base64UrlSafe::encodeUnpadded($challenge);

        $allow = UserPasskey::where('user_id', $user->id)->get()->map(
            fn (UserPasskey $p) => [
                'type' => 'public-key',
                'id' => $p->credential_id,
                'transports' => $p->transports ?? [],
            ]
        )->all();

        $key = $ticket ?? Helper::guid();
        if ($ticket === null) {
            Cache::put(CacheKey::get('PASSKEY_LOGIN_TICKET', $key), $user->id, self::TICKET_TTL);
        }
        Cache::put(
            CacheKey::get('PASSKEY_AUTH_CHALLENGE', $key),
            [
                'challenge' => $challengeB64,
                'rp_id' => $rpId,
                'origins' => $origins,
                'user_id' => $user->id,
            ],
            self::TICKET_TTL
        );

        return [
            'ticket' => $key,
            'options' => [
                'challenge' => $challengeB64,
                'rpId' => $rpId,
                'allowCredentials' => $allow,
                'userVerification' => 'preferred',
                'timeout' => 60000,
            ],
        ];
    }

    /**
     * 校验登录断言，成功返回用户（调用方负责签发 token + 写登录日志）。
     */
    public function verifyAuthentication(string $ticket, array $credentialJson, ?string $requestOrigin = null): User
    {
        $userId = Cache::get(CacheKey::get('PASSKEY_LOGIN_TICKET', $ticket));
        $stored = Cache::get(CacheKey::get('PASSKEY_AUTH_CHALLENGE', $ticket));
        if (!$userId || !$stored || !isset($stored['challenge'])) {
            abort(400, __('Login session expired, please login again'));
        }
        $user = User::find($userId);
        if (!$user || $user->banned) {
            abort(400, __('User not found'));
        }

        $serializer = $this->serializer();
        /** @var PublicKeyCredential $credential */
        $credential = $serializer->denormalize($credentialJson, PublicKeyCredential::class, 'json');
        $response = $credential->response;
        if (!$response instanceof \Webauthn\AuthenticatorAssertionResponse) {
            abort(400, __('Invalid passkey response'));
        }

        $rawId = $credential->rawId !== '' ? $credential->rawId : Base64UrlSafe::decodeNoPadding((string) ($credentialJson['rawId'] ?? ''));
        if ($rawId === '') {
            abort(400, __('Invalid passkey response'));
        }
        $credentialId = Base64UrlSafe::encodeUnpadded($rawId);
        $passkey = UserPasskey::where('user_id', $user->id)->where('credential_id', $credentialId)->first();
        if (!$passkey) {
            abort(400, __('Unknown passkey'));
        }

        $rpId = $stored['rp_id'] ?? $this->rpId();
        $origins = $this->allowedOrigins($requestOrigin);
        if (empty($origins) && !empty($stored['origins'])) {
            $origins = $stored['origins'];
        }

        $record = \Webauthn\CredentialRecord::create(
            Base64UrlSafe::decodeNoPadding($passkey->credential_id),
            $passkey->type ?: 'public-key',
            $passkey->transports ?? [],
            $passkey->attestation_type ?: 'none',
            new \Webauthn\TrustPath\EmptyTrustPath(),
            Uuid::fromString($this->normalizeAaguid($passkey->aaguid)),
            base64_decode($passkey->credential_public_key),
            (string) $user->id,
            (int) $passkey->counter,
            null,
            (bool) $passkey->backup_eligible,
            (bool) $passkey->backup_status
        );

        $requestOptions = PublicKeyCredentialRequestOptions::create(
            $stored['challenge'],
            $rpId,
            UserPasskey::where('user_id', $user->id)->get()->map(
                fn (UserPasskey $p) => PublicKeyCredentialDescriptor::create(
                    'public-key',
                    Base64UrlSafe::decodeNoPadding($p->credential_id),
                    $p->transports ?? []
                )
            )->all(),
            PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_PREFERRED,
            60000
        );

        $factory = $this->ceremonyFactory($origins);
        $validator = AuthenticatorAssertionResponseValidator::create($factory->requestCeremony());
        $updatedRecord = $validator->check($record, $response, $requestOptions, $rpId, (string) $user->id);

        $passkey->counter = $updatedRecord->counter;
        $passkey->backup_eligible = (bool) $updatedRecord->backupEligible;
        $passkey->backup_status = (bool) $updatedRecord->backupStatus;
        $passkey->last_used_at = time();
        $passkey->save();

        Cache::forget(CacheKey::get('PASSKEY_AUTH_CHALLENGE', $ticket));
        Cache::forget(CacheKey::get('PASSKEY_LOGIN_TICKET', $ticket));

        return $user;
    }

    private function normalizeAaguid(mixed $aaguid): string
    {
        if (is_string($aaguid) && $aaguid !== '' && Uuid::isValid($aaguid)) {
            return $aaguid;
        }
        return Uuid::v4()->toRfc4122();
    }

    public function issueLoginTicket(User $user): string
    {
        $ticket = Helper::guid();
        Cache::put(CacheKey::get('PASSKEY_LOGIN_TICKET', $ticket), $user->id, self::TICKET_TTL);
        return $ticket;
    }
}
