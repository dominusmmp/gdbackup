<?php
/**
 * Database Backup To Google Drive
 *
 * @copyright 2022 dominusmmp
 * @license MIT
 * 
 */

require_once __DIR__ . "/simple_encryption.php";

class GDriveAPIv3 extends SimpleEncryption
{
    // Google API Client ID & Secret & Redirect URL
    private $client_id, $client_secret, $redirect_uri;

    // Google API Auth Code & Refresh Token & Access Token
    private $auth_code, $refresh_token, $access_token;

    // Google API Urls
    private $auth_url, $api_url;

    // Erro Messages List
    private $error = array();

    /**
     * Class GDRIVEAPIv3 Constructor
     * @example
     * $gdrive = new GDRIVEAPIv3(array(
     * "client_id" => "",
     * "client_secret" => "",
     * "redirect_uri" => "",
     * "auth_code" => "",
     * "refresh_token_password" => "",
     * "refresh_token_key" => ""
     * ));
     */
    public function __construct($args)
    {
        // Check If Required Arguments Are Empty
        if (empty($args["client_id"])) {
            $this->error[] = "Argument 'client_id' is required!";
        }

        if (empty($args["client_secret"])) {
            $this->error[] = "Argument 'client_secret' is required!";
        }

        if (empty($args["redirect_uri"])) {
            $this->error[] = "Argument 'redirect_uri' is required!";
        }

        if (empty($args["auth_code"])) {
            $this->error[] = "Argument 'auth_code' is required!";
        }

        if (empty($args["refresh_token_password"])) {
            $this->error[] = "Argument 'refresh_token_password' is required!";
        }

        if (empty($args["refresh_token_key"])) {
            $this->error[] = "Argument 'refresh_token_key' is required!";
        }

        // Bind Arguments To Class Private Variables
        $this->client_id = $args["client_id"];
        $this->client_secret = $args["client_secret"];
        $this->redirect_uri = $args["redirect_uri"];
        $this->auth_code = $args["auth_code"];
        $this->refresh_token_password = $args["refresh_token_password"];
        $this->refresh_token_key = $args["refresh_token_key"];

        // Define Class Private Variables
        $this->auth_url = "https://oauth2.googleapis.com/token";
        $this->api_url = "https://www.googleapis.com";
    }

    /** Get New Refresh Token */
    private function get_refresh_token()
    {
        // Check If There's No Problem With Required Arguments
        if (count($this->error) > 0) {
            return array(
                "error" => 1,
                "message" => $this->error,
            );
        }

        // First Check And Read Refresh Token From Json File If Exists
        if (file_exists(__DIR__ . "/refresh_token.json")) {
            $json_refresh_token = file_get_contents(__DIR__ . "/refresh_token.json");
            $arr_refresh_token = json_decode($json_refresh_token, true);

            if (!empty($arr_refresh_token["encrypted_refresh_token"])) {
                $decrypted_refresh_token = $this->decrypt(array(
                    "encrypted_msg" => $arr_refresh_token["encrypted_refresh_token"],
                    "encryption_password" => $this->refresh_token_password,
                    "encryption_key" => $this->refresh_token_key,
                ));
            }

            // Return Refresh Token From The Json File As Result
            if (empty($decrypted_refresh_token["error"])) {
                return array(
                    "error" => 0,
                    "result" => $decrypted_refresh_token["result"],
                );
            } else if (!empty($decrypted_refresh_token["error"])) {
                print_r($decrypted_refresh_token["result"]);
            }
        }

        // HTTP Headers Data To Get Refresh Token
        $headers = array();
        $headers[] = "POST /token HTTP/1.1";
        $headers[] = "Host: oauth2.googleapis.com";
        $headers[] = "Content-Type: application/x-www-form-urlencoded";

        // POST Data To Get Refresh Token
        $arr_post_data = array();
        $arr_post_data["client_id"] = $this->client_id;
        $arr_post_data["client_secret"] = $this->client_secret;
        $arr_post_data["code"] = $this->auth_code;
        $arr_post_data["grant_type"] = "authorization_code";
        $arr_post_data["redirect_uri"] = $this->redirect_uri;

        // Send Refresh Token Request And Get Response
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->auth_url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($arr_post_data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        $result = curl_exec($ch);
        curl_close($ch);

        // Decode Json Response Data
        $json_result = json_decode($result, true);

        // Return Refresh Token As Result And Save It In Json File
        if (empty($json_result["error_description"]) && !empty($json_result["refresh_token"])) {

            // Save Refresh Token In Json File
            $encrypted_refresh_token = $this->encrypt(array(
                "fresh_msg" => $json_result["refresh_token"],
                "encryption_password" => $this->refresh_token_password,
                "encryption_key" => $this->refresh_token_key,
            ));
            $encrypted_refresh_token_arr = array(
                "encrypted_refresh_token" => $encrypted_refresh_token["result"],
            );
            $json_refresh_token = fopen(__DIR__ . "/refresh_token.json", "w");
            fwrite($json_refresh_token, json_encode($encrypted_refresh_token_arr));
            fclose($json_refresh_token);

            // Return Refresh Token As Result
            return array(
                "error" => 0,
                "result" => $json_result["refresh_token"],
            );

        } else {
            if (!empty($json_result["error_description"])) {
                return array(
                    "error" => 1,
                    "message" => "Failed to get Google API Refresh Token! Error: " . $json_result["error_description"],
                );

            } else {
                return array(
                    "error" => 1,
                    "message" => "Failed to get Google API Refresh Token! Error message: Unknown Error",
                );
            }
        }
    }

    /** Get New Access Token */
    private function set_access_token()
    {
        // Check If There's No Problem With Required Arguments
        if (count($this->error) > 0) {
            return array(
                "error" => 1,
                "message" => $this->error,
            );
        }

        // Get Refresh Token As A Required Argument
        if (empty($this->refresh_token)) {
            $refresh_token = $this->get_refresh_token();
            if (empty($refresh_token["error"]) && !empty($refresh_token["result"])) {
                $this->refresh_token = $refresh_token["result"];
            } else {
                return array(
                    "error" => 1,
                    "message" => $refresh_token["message"],
                );
            }
        }

        // HTTP Headers Data To Get Access Token
        $headers = array();
        $headers[] = "POST /token HTTP/1.1";
        $headers[] = "Host: oauth2.googleapis.com";
        $headers[] = "Content-Type: application/x-www-form-urlencoded";

        // POST Data To Get Access Token
        $arr_post_data = array();
        $arr_post_data["client_id"] = $this->client_id;
        $arr_post_data["client_secret"] = $this->client_secret;
        $arr_post_data["refresh_token"] = $this->refresh_token;
        $arr_post_data["grant_type"] = "refresh_token";
        $arr_post_data["redirect_uri"] = $this->redirect_uri;

        // Send Access Token Request And Get Response
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->auth_url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($arr_post_data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        $result = curl_exec($ch);
        curl_close($ch);

        // Decode Json Response Data
        $json_result = json_decode($result, true);

        // Return Access Token As Result
        if (empty($json_result["error_description"]) && !empty($json_result["access_token"])) {
            return array(
                "error" => 0,
                "result" => $json_result["access_token"],
            );
        } else {
            if (!empty($json_result["error_description"])) {
                return array(
                    "error" => 1,
                    "message" => "Failed to get Google API Access Token! Error: " . $json_result["error_description"],
                );
            } else {
                return array(
                    "error" => 1,
                    "message" => "Failed to get Google API Access Token! Error message: Unknown Error",
                );
            }
        }
    }

    /**
     * Find Folder at Google Drive
     * @example
     * $result_id = search(array(
     * "root_id" => "",
     * "name" => "",
     * "type" => "", // "file" / "folder"
     * "dateAfter" => "", // find files / folders created after this date in this format -> date('Y-m-d\TH:i:s') = "2022-01-28T15:59:38"
     * "dateBefore" => "", // find files / folders created before this date in this format -> date('Y-m-d\TH:i:s') = "2022-01-28T15:59:38"
     * ));
     */
    public function search(array $args)
    {
        // Check If There's No Problem With Required Arguments
        if (count($this->error) > 0) {
            return array(
                "error" => 1,
                "message" => $this->error,
            );
        }

        // Check If Search Required Arguments Are Empty
        if (empty($args)) {
            return array(
                "error" => 1,
                "message" => "Empty search fields!",
            );
        }

        // Set Access Token If Does Not Exist
        if (empty($this->access_token)) {
            $access_token = $this->set_access_token();
            if (empty($access_token["error"]) && !empty($access_token["result"])) {
                $this->access_token = $access_token["result"];
            } else {
                return array(
                    "error" => 1,
                    "message" => $access_token["message"],
                );
            }
        }

        // Creating Search Array
        $arr_search = [];
        foreach ($args as $key => $value) {
            // Root Folder To Search Through
            // Ex: 'root_folder_id_to_search_in' in parents
            if ($key == "root_id" && !empty($value)) {
                $arr_search[$key] = "'" . $value . "' in parents";
            }

            // File / Folder Name To Search For
            // Ex: name = 'name_of_folder_or_file'
            if ($key == "name" && isset($value)) {
                $arr_search[$key] = "name = '" . str_replace("'", "\'", $value) . "'";
            }

            // File / Folder Type
            // Ex: mimeType = 'application/vnd.google-apps.folder'
            if ($key == "type" && !empty($value)) {
                if ($value == "file") {
                    $arr_search[$key] = "mimeType != 'application/vnd.google-apps.folder'";
                } elseif ($value == "folder") {
                    $arr_search[$key] = "mimeType = 'application/vnd.google-apps.folder'";
                }
            }

            // Date To Fine Items Created After This Date
            // Ex: createdTime > '2012-06-04T12:00:00'
            if ($key == "dateAfter" && !empty($value)) {
                $arr_search[$key] = "createdTime > '" . $value . "'";
            }

            // Date To Fine Items Created After This Date
            // Ex: createdTime > '2012-06-04T12:00:00'
            if ($key == "dateBefore" && !empty($value)) {
                $arr_search[$key] = "createdTime < '" . $value . "'";
            }
        }

        // Checking If Search Array Is Empty
        if (!empty($arr_search)) {
            // Skip Searching Trashed Files / Folders
            $arr_search["trashed"] = "trashed = false";

            // Creating Search Encoded String
            $str_search = urlencode(implode(" and ", $arr_search));
        } else {
            return array(
                "error" => 1,
                "message" => "Invalid values!",
            );
        }

        // Set Full Search Url With Search Arguments
        $search_url = $this->api_url . "/drive/v3/files?q=" . $str_search;

        // HTTP Headers Data To Search File(s)/Folder(s)
        $headers = array();
        $headers[] = "Content-Type: application/json";
        $headers[] = "Authorization: OAuth " . urlencode($this->access_token);

        // Send Search Request And Get Response
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $search_url);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        $result = curl_exec($ch);
        curl_close($ch);

        // Decode Json Response Data
        $json_result = json_decode($result, true);

        // Return File(s)/Folder(s) Search Result
        if (empty($json_result["error"]) && empty($json_result["error_description"])) {
            if (!empty($json_result["files"]) && $json_result["incompleteSearch"] === false) {
                $arr_files = [];
                foreach ($json_result["files"] as $file) {
                    $arr_files[] = $file["id"];
                }

                return array(
                    "error" => 0,
                    "result" => $arr_files,
                );
            } elseif ($json_result["incompleteSearch"] === true) {
                return array(
                    "error" => 1,
                    "message" => "Search was incomplete!",
                );
            } else {
                return array(
                    "error" => 1,
                    "message" => "Nothing Found!",
                );
            }
        } else {
            if (!empty($json_result["error"]["message"])) {
                return array(
                    "error" => 1,
                    "message" => "Failed to find the searched item(s)! Error: " . $json_result["error"]["message"],
                );
            } else {
                return array(
                    "error" => 1,
                    "message" => "Failed to find the searched item(s)! Error message: Unknown Error",
                );
            }
        }
    }

    /**
     * Delete Files From Google Drive
     * @example
     * $delete_file = delete(["gsdfhsdhf", "sdagasga"]);
     */
    public function delete(array $fileIds)
    {
        // Check If Required Arguments Are Empty
        if (empty($fileIds)) {
            $this->error[] = "File/Folder ids as an array is required!";
        } elseif (!is_array($fileIds)) {
            $this->error[] = "File/Folder ids should be in an array!";
        }

        // Check If There's No Problem With Required Arguments
        if (count($this->error) > 0) {
            return array(
                "error" => 1,
                "message" => $this->error,
            );
        }

        // Set Access Token If Does Not Exist
        if (empty($this->access_token)) {
            $access_token = $this->set_access_token();
            if (empty($access_token["error"]) && !empty($access_token["result"])) {
                $this->access_token = $access_token["result"];
            } else {
                return array(
                    "error" => 1,
                    "message" => $access_token["message"],
                );
            }
        }

        // HTTP Headers Data To Create Folder
        $headers = array();
        $headers[] = "Content-Type: application/json";
        $headers[] = "Authorization: OAuth " . urlencode($this->access_token);

        $delete_result = [];
        foreach ($fileIds as $fileId) {
            // Folder Create Url
            $delete_url = $this->api_url . "/drive/v3/files/" . $fileId;

            // Send File Delete Request And Get Response
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $delete_url);
            curl_setopt($ch, CURLOPT_HEADER, false);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            $result = curl_exec($ch);
            curl_close($ch);

            // Decode Json Response Data
            $json_result = json_decode($result, true);

            if (empty($json_result)) {
                $delete_result[] = array(
                    "error" => 0,
                    "result" => "File: " . $fileId . " is successfully deleted!",
                );
            } else {
                if (!empty($json_result["error"]["message"])) {
                    $delete_result[] = array(
                        "error" => 1,
                        "message" => "Failed to delete file: " . $fileId . " ! Error message: " . $json_result["error"]["message"],
                    );
                } else {
                    $delete_result[] = array(
                        "error" => 1,
                        "message" => "Failed to delete file: " . $fileId . " ! Error message: Unknown Error",
                    );
                }
            }
        }
        // Return Files Delete Results As Array
        return $delete_result;
    }

    /**
     * Create Folder at Google Drive
     * @example
     * $created_folder_id = create_folder(array(
     * "root_id" => "",
     * "folder_name" => ""
     * ));
     */
    public function create_folder(array $args)
    {
        // Check If Required Arguments Are Empty
        if (empty($args["folder_name"])) {
            $this->error[] = "Argument 'folder_name' is required!";
        }

        // Check If There's No Problem With Required Arguments
        if (count($this->error) > 0) {
            return array(
                "error" => 1,
                "message" => $this->error,
            );
        }

        // Set Access Token If Does Not Exist
        if (empty($this->access_token)) {
            $access_token = $this->set_access_token();
            if (empty($access_token["error"]) && !empty($access_token["result"])) {
                $this->access_token = $access_token["result"];
            } else {
                return array(
                    "error" => 1,
                    "message" => $access_token["message"],
                );
            }
        }

        // Check Folder If Exists
        $folder_id = $this->search(array(
            "root_id" => !empty($args["root_id"]) ? $args["root_id"] : "",
            "name" => $args["folder_name"],
            "type" => "folder",
        ));

        // Return Folder ID AS Result If Exists
        if (empty($folder_id["error"]) && !empty($folder_id["result"]) && is_array($folder_id["result"])) {
            return array(
                "error" => 0,
                "result" => $folder_id["result"][0],
            );
        }

        // Folder Create Url
        $create_url = $this->api_url . "/drive/v3/files";

        // HTTP Headers Data To Create Folder
        $headers = array();
        $headers[] = "Content-Type: application/json";
        $headers[] = "Authorization: OAuth " . urlencode($this->access_token);

        // POST Data To Create Folder
        $arr_post_data = array();
        $arr_post_data["name"] = $args["folder_name"];
        if (!empty($args["root_id"])) {
            $arr_post_data["parents"] = array($args["root_id"]);
        }
        $arr_post_data["mimeType"] = "application/vnd.google-apps.folder";

        // Send Folder Creation Request And Get Response
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $create_url);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($arr_post_data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        $result = curl_exec($ch);
        curl_close($ch);

        // Decode Json Response Data
        $json_result = json_decode($result, true);

        // Return Created Folder ID AS Result
        if (empty($json_result["error"]) && !empty($json_result["id"])) {
            return array(
                "error" => 0,
                "result" => $json_result["id"],
            );
        } else {
            if (!empty($json_result["error"]["message"])) {
                return array(
                    "error" => 1,
                    "message" => "Failed to create new folder! Error message: " . $json_result["error"]["message"],
                );
            } else {
                return array(
                    "error" => 1,
                    "message" => "Failed to create new folder! Error message: Unknown Error",
                );
            }
        }
    }

    /**
     * Upload Files to Google Drive
     * @example
     * $created_folder_id = upload_file(array(
     * "path_to_file" => "",
     * "data_content" => "",
     * "file_name" => "",
     * "root_id" => "",
     * "ignore_existed" => true
     * ));
     */
    public function upload_file(array $args)
    {
        // Check If Required Arguments Are Empty
        if (empty($args["path_to_file"]) && empty($args["data_content"])) {
            $this->error[] = "Argument ('path_to_file' or 'data_content') is required!";
        }

        if (empty($args["file_name"])) {
            $this->error[] = "Argument 'file_name' is required!";
        }

        // Check If There's No Problem With Required Arguments
        if (count($this->error) > 0) {
            return array(
                "error" => 1,
                "message" => $this->error,
            );
        }

        // Check If Should Ignore File Name Existance In Google Drive Root
        if (!empty($args["ignore_existed"])) {
            $args["ignore_existed"] = ($args["ignore_existed"] === true) ? false : true;
        } else {
            $args["ignore_existed"] = true;
        }

        // Check File If Exists
        if ($args["ignore_existed"]) {
            $file_existed = $this->search(array(
                "root_id" => !empty($args["root_id"]) ? $args["root_id"] : "",
                "name" => $args["file_name"],
                "type" => "file",
            ));

            if (empty($file_existed["error"]) && !empty($file_existed["result"]) && is_array($file_existed["result"])) {
                return array(
                    "error" => 0,
                    "result" => $file_existed["result"][0],
                );
            }
        }

        // Get Content To Upload
        if (!empty($args["path_to_file"])) {
            $content_to_upload = file_get_contents($args["path_to_file"]);
        }

        if (!empty($args["data_content"])) {
            $content_to_upload = $args["data_content"];
        }

        // Set Access Token If Does Not Exist
        if (empty($this->access_token)) {
            $access_token = $this->set_access_token();
            if (empty($access_token["error"]) && !empty($access_token["result"])) {
                $this->access_token = $access_token["result"];
            } else {
                return array(
                    "error" => 1,
                    "message" => $access_token["message"],
                );
            }
        }

        // File Create Url
        $file_create_url = $this->api_url . "/upload/drive/v3/files?uploadType=resumable";

        // HTTP Headers Data To Create File
        $headers = array();
        $headers[] = "Content-Type: application/json";
        $headers[] = "Authorization: OAuth " . urlencode($this->access_token);

        // POST Data To Create File
        $arr_post_data = array();
        $arr_post_data["name"] = $args["file_name"];
        if (!empty($args["root_id"])) {
            $arr_post_data["parents"] = array($args["root_id"]);
        }

        // Send File Creation Request And Get Response
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $file_create_url);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($arr_post_data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        $result = curl_exec($ch);
        curl_close($ch);

        // Read Response Header To Get File File Upload Url
        preg_match_all("|location: (.*)\\n|U", $result, $arr_upload_url, PREG_PATTERN_ORDER);
        $file_upload_url = !empty($arr_upload_url[1][0]) ? $arr_upload_url[1][0] : null;

        // HTTP Headers Data To Upload File Data
        $headers = array();
        $headers[] = "Content-Type: application/json";
        $headers[] = "Authorization: OAuth " . urlencode($this->access_token);

        // Send File Data Upload Request And Get Response
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_URL, trim($file_upload_url));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
        curl_setopt($ch, CURLOPT_BINARYTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $content_to_upload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        $result = curl_exec($ch);
        curl_close($ch);

        // Decode Json Response Data
        $json_result = json_decode($result, true);

        // Return File ID AS Result
        if (empty($json_result["error"]) && !empty($json_result["id"])) {
            return array(
                "error" => 0,
                "result" => $json_result["id"],
            );
        } else {
            if (!empty($json_result["error"]["message"])) {
                return array(
                    "error" => 1,
                    "message" => "Failed to upload the file! Error message: " . $json_result["error"]["message"],
                );
            } else {
                return array(
                    "error" => 1,
                    "message" => "Failed to upload the file! Error message: Unknown Error",
                );
            }
        }
    }
}
