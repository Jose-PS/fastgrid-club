<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use CBOR\ByteStringObject;
use CBOR\MapObject;
use CBOR\NegativeIntegerObject;
use CBOR\TextStringObject;
use CBOR\UnsignedIntegerObject;
use OpenSSLAsymmetricKey;
use ParagonIE\ConstantTime\Base64UrlSafe;
use RuntimeException;

final class VirtualAuthenticator
{
    private const int FlagUserPresent = 0x01;

    private const int FlagUserVerified = 0x04;

    private const int FlagAttestedCredentialData = 0x40;

    public readonly string $credentialId;

    private OpenSSLAsymmetricKey $privateKey;

    private string $userHandle = '';

    public function __construct(
        private string $rpId,
        private string $origin,
    ) {
        $this->credentialId = random_bytes(32);

        $key = openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);

        if ($key === false) {
            throw new RuntimeException('Unable to create the test key.');
        }

        $this->privateKey = $key;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function register(array $options, int $signCount = 0, ?string $origin = null, bool $userVerified = true): array
    {
        $this->userHandle = Base64UrlSafe::decodeNoPadding($options['user']['id']);

        $clientData = $this->clientData('webauthn.create', $options['challenge'], $origin);

        $authData = $this->authenticatorData($signCount, $userVerified)
            .str_repeat("\0", 16)
            .pack('n', strlen($this->credentialId))
            .$this->credentialId
            .$this->coseKey();

        $attestationObject = (string) MapObject::create()
            ->add(TextStringObject::create('fmt'), TextStringObject::create('none'))
            ->add(TextStringObject::create('attStmt'), MapObject::create())
            ->add(TextStringObject::create('authData'), ByteStringObject::create($authData));

        return [
            ...$this->credentialEnvelope(),
            'response' => [
                'clientDataJSON' => Base64UrlSafe::encodeUnpadded($clientData),
                'attestationObject' => Base64UrlSafe::encodeUnpadded($attestationObject),
                'transports' => ['internal'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function assert(array $options, int $signCount, ?string $origin = null, bool $userVerified = true): array
    {
        $clientData = $this->clientData('webauthn.get', $options['challenge'], $origin);
        $authData = $this->authenticatorData($signCount, $userVerified, attested: false);

        openssl_sign($authData.hash('sha256', $clientData, true), $signature, $this->privateKey, OPENSSL_ALGO_SHA256);

        return [
            ...$this->credentialEnvelope(),
            'response' => [
                'clientDataJSON' => Base64UrlSafe::encodeUnpadded($clientData),
                'authenticatorData' => Base64UrlSafe::encodeUnpadded($authData),
                'signature' => Base64UrlSafe::encodeUnpadded($signature),
                'userHandle' => Base64UrlSafe::encodeUnpadded($this->userHandle),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function credentialEnvelope(): array
    {
        $id = Base64UrlSafe::encodeUnpadded($this->credentialId);

        return [
            'id' => $id,
            'rawId' => $id,
            'type' => 'public-key',
            'clientExtensionResults' => [],
            'authenticatorAttachment' => 'platform',
        ];
    }

    private function clientData(string $type, string $challenge, ?string $origin): string
    {
        return json_encode([
            'type' => $type,
            'challenge' => $challenge,
            'origin' => $origin ?? $this->origin,
            'crossOrigin' => false,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function authenticatorData(int $signCount, bool $userVerified, bool $attested = true): string
    {
        $flags = self::FlagUserPresent
            | ($userVerified ? self::FlagUserVerified : 0)
            | ($attested ? self::FlagAttestedCredentialData : 0);

        return hash('sha256', $this->rpId, true).chr($flags).pack('N', $signCount);
    }

    private function coseKey(): string
    {
        $details = openssl_pkey_get_details($this->privateKey);

        if ($details === false) {
            throw new RuntimeException('Unable to read the test key.');
        }

        return (string) MapObject::create()
            ->add(UnsignedIntegerObject::create(1), UnsignedIntegerObject::create(2))
            ->add(UnsignedIntegerObject::create(3), NegativeIntegerObject::create(-7))
            ->add(NegativeIntegerObject::create(-1), UnsignedIntegerObject::create(1))
            ->add(NegativeIntegerObject::create(-2), ByteStringObject::create(str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT)))
            ->add(NegativeIntegerObject::create(-3), ByteStringObject::create(str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT)));
    }
}
