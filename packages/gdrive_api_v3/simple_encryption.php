<?php
/**
 * Database Backup To Google Drive
 *
 * @copyright 2022 dominusmmp
 * @license MIT
 * 
 */

class SimpleEncryption
{
    // Error Messages List
    private $error = array();

    /**
     * Get Random StringGet Random String
     * @example
     * $random_string = rand_str(32));
     */
    public function rand_str(int $length = 64)
    {
        $length = (empty($length) || $length < 4) ? 4 : $length;
        return bin2hex(random_bytes(($length - ($length % 2)) / 2));
    }

    /**
     * Encrypt Data
     * @example
     * $encrypted_message = encrypt(array(
     * "fresh_msg" => "",
     * "encryption_password" => "",
     * "encryption_key" => ""
     * ));
     */
    public function encrypt(array $args)
    {
        if (empty($args["fresh_msg"])) {
            $this->error[] = "Argument 'fresh_msg' is missing!";
        }

        if (empty($args["encryption_password"])) {
            $this->error[] = "Argument 'encryption_password' is missing!";
        }

        if (empty($args["encryption_key"])) {
            $this->error[] = "Argument 'encryption_key' is missing!";
        }

        if (count($this->error) > 0) {
            return array(
                "error" => 1,
                "result" => $this->error,
            );
        }

        $password = hash_hmac(
            "sha256",
            $args["encryption_password"],
            base64_encode($args["encryption_key"]));

        $iv = substr(sha1(mt_rand()), 0, 16);

        $encrypted_msg = openssl_encrypt(
            $args["fresh_msg"],
            "aes-256-cbc",
            $password,
            null,
            $iv
        );

        $encrypted_bundle = $iv . $encrypted_msg;

        return array(
            "error" => 0,
            "result" => $encrypted_bundle,
        );
    }

    /**
     * Decrypt Data
     * @example
     * $decrypted_message = decrypt(array(
     * "encrypted_msg" => "",
     * "encryption_password" => "",
     * "encryption_key" => ""
     * ));
     */
    public function decrypt(array $args)
    {
        if (empty($args["encrypted_msg"])) {
            $this->error[] = "Argument 'encrypted_msg' is missing!";
        }

        if (empty($args["encryption_password"])) {
            $this->error[] = "Argument 'encryption_password' is missing!";
        }

        if (empty($args["encryption_key"])) {
            $this->error[] = "Argument 'encryption_key' is missing!";
        }

        if (count($this->error) > 0) {
            return array(
                "error" => 1,
                "result" => $this->error,
            );
        }

        $password = hash_hmac(
            "sha256",
            $args["encryption_password"],
            base64_encode($args["encryption_key"])
        );

        $iv = substr($args["encrypted_msg"], 0, 16);

        $encrypted_msg = substr($args["encrypted_msg"], 16, -1);

        $decrypted_msg = openssl_decrypt(
            $encrypted_msg,
            "aes-256-cbc",
            $password,
            null,
            $iv
        );

        return array(
            "error" => 0,
            "result" => $decrypted_msg,
        );
    }
}
