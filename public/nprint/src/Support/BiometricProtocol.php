<?php

namespace App\Support;

/**
 * Contrato GSBIO1 del agente biométrico (esta PC con lector).
 *
 * El agente tiene dos llaves propias que genera él mismo la primera vez y que nunca salen de esta PC:
 *  - firma (Ed25519): firma cada resultado; la nube y el Edge la verifican con la llave pública registrada;
 *  - buzón (X25519): quien pide la lectura cifra las plantillas para este agente.
 *
 * Las plantillas llegan cifradas y ligadas al reto: X25519(efímera, buzón) → HKDF-SHA256 → AES-256-GCM, con el
 * reto como datos asociados. Así la app que transporta la petición no puede leerlas ni cambiar el reto sin que
 * falle el descifrado, y el resultado firmado no se puede reutilizar para otra acción u otro aparato.
 *
 * Las llaves viven FUERA de public/: `php -S` sirve como archivo cualquier cosa dentro de la carpeta pública.
 */
final class BiometricProtocol
{
    public const VERSION = 1;
    private const HKDF_INFO = 'GSBIO1 sealed';
    private const MAX_TTL_S = 180;

    private string $dir;

    public function __construct(?string $secretsDir = null)
    {
        $this->dir = $secretsDir ?? dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'secrets';
    }

    public static function available(): bool
    {
        return function_exists('sodium_crypto_sign_detached') && function_exists('openssl_decrypt');
    }

    /** @return array{signPublicKey: string, boxPublicKey: string} */
    public function identity(): array
    {
        [$signPk] = $this->signKeys();
        [$boxPk] = $this->boxKeys();
        return ['signPublicKey' => base64_encode($signPk), 'boxPublicKey' => base64_encode($boxPk)];
    }

    /**
     * Valida la forma del reto. Devuelve el reto normalizado o null.
     * @param mixed $c
     */
    public static function normalizeChallenge($c, ?int $now = null): ?array
    {
        if (!is_array($c)) return null;
        $now = $now ?? time();
        $nonce = (string)($c['nonce'] ?? '');
        $restaurantId = $c['restaurantId'] ?? null;
        $agentId = $c['agentId'] ?? null;
        $deviceId = $c['deviceId'] ?? 0;
        $action = (string)($c['action'] ?? '');
        $targetId = (string)($c['targetId'] ?? '');
        $purpose = (string)($c['purpose'] ?? '');
        $exp = $c['exp'] ?? null;

        if (!preg_match('/^[0-9a-f]{64}$/', $nonce)) return null;
        foreach ([$restaurantId, $agentId, $exp] as $n) {
            if (!is_int($n) || $n <= 0) return null;
        }
        if (!is_int($deviceId) || $deviceId < 0) return null;
        if (!preg_match('/^[a-z][a-z0-9_.]{0,63}$/', $action)) return null;
        if (!preg_match('/^[A-Za-z0-9_.:-]{0,64}$/', $targetId)) return null;
        if (!in_array($purpose, ['approval', 'attendance'], true)) return null;
        if ($exp < $now || $exp > $now + self::MAX_TTL_S) return null;

        return compact('nonce', 'restaurantId', 'agentId', 'deviceId', 'action', 'targetId', 'purpose', 'exp');
    }

    public static function challengeString(array $c): string
    {
        return implode('|', [
            'GSBIO1', $c['nonce'], $c['restaurantId'], $c['agentId'], $c['deviceId'],
            $c['action'], $c['targetId'], $c['purpose'], $c['exp'],
        ]);
    }

    public static function resultString(array $c, bool $matched, int $userId): string
    {
        return self::challengeString($c) . '|' . ($matched ? '1' : '0') . '|' . ($matched ? $userId : 0);
    }

    /**
     * Descifra las plantillas. Devuelve el arreglo decodificado o null si algo no cuadra.
     * @param mixed $sealed
     */
    public function open(array $challenge, $sealed): ?array
    {
        if (!is_array($sealed)) return null;
        $epk = base64_decode((string)($sealed['epk'] ?? ''), true);
        $iv = base64_decode((string)($sealed['iv'] ?? ''), true);
        $ct = base64_decode((string)($sealed['ct'] ?? ''), true);
        $tag = base64_decode((string)($sealed['tag'] ?? ''), true);
        if ($epk === false || strlen($epk) !== 32 || $iv === false || strlen($iv) !== 12
            || $ct === false || $ct === '' || $tag === false || strlen($tag) !== 16) {
            return null;
        }

        [$boxPk, $boxSk] = $this->boxKeys();
        try {
            $shared = sodium_crypto_scalarmult($boxSk, $epk);
        } catch (\Throwable $e) {
            return null;
        }
        $key = hash_hkdf('sha256', $shared, 32, self::HKDF_INFO, $epk . $boxPk);
        $plain = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, self::challengeString($challenge));
        if ($plain === false) return null;

        $data = json_decode($plain, true);
        return is_array($data) ? $data : null;
    }

    public function sign(array $challenge, bool $matched, int $userId): string
    {
        [, $signSk] = $this->signKeys();
        return base64_encode(sodium_crypto_sign_detached(self::resultString($challenge, $matched, $userId), $signSk));
    }

    /** @return array{0: string, 1: string} [pública, secreta] */
    private function signKeys(): array
    {
        $kp = $this->loadOrCreate('agent-sign.key', fn () => sodium_crypto_sign_keypair(), SODIUM_CRYPTO_SIGN_KEYPAIRBYTES);
        return [sodium_crypto_sign_publickey($kp), sodium_crypto_sign_secretkey($kp)];
    }

    /** @return array{0: string, 1: string} [pública, secreta] */
    private function boxKeys(): array
    {
        $kp = $this->loadOrCreate('agent-box.key', fn () => sodium_crypto_box_keypair(), SODIUM_CRYPTO_BOX_KEYPAIRBYTES);
        return [sodium_crypto_box_publickey($kp), sodium_crypto_box_secretkey($kp)];
    }

    private function loadOrCreate(string $name, callable $make, int $len): string
    {
        $path = $this->dir . DIRECTORY_SEPARATOR . $name;
        if (is_file($path)) {
            $raw = base64_decode(trim((string)file_get_contents($path)), true);
            if ($raw !== false && strlen($raw) === $len) return $raw;
            throw new \RuntimeException("llave_agente_danada:$name");
        }
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            throw new \RuntimeException('sin_carpeta_de_llaves');
        }
        $kp = $make();
        $tmp = $path . '.tmp';
        if (file_put_contents($tmp, base64_encode($kp), LOCK_EX) === false || !rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('no_se_pudo_guardar_llave');
        }
        return $kp;
    }
}
