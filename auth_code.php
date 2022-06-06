<?php
/**
 * Database Backup To Google Drive
 *
 * @copyright 2022 dominusmmp
 * @license MIT
 *
 */

/**
 * Google API Client ID (Should Be Obtain From https://console.cloud.google.com/apis/credentials)
 * Fill This Parameter With Your Own Google API Client ID
 */
$google_client_id = "";






/**
 *
 * 
 * 
 * 
 * 
 * Don"t Touch The Rest Of The Codes
 *
 * 
 * 
 * 
 * 
 */

$file_url = (isset($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] === "on" ? "https" : "http") . "://" . $_SERVER["HTTP_HOST"] . $_SERVER["REQUEST_URI"];
$google_redirect_url = str_replace(":", "%3A", $file_url);
$google_auth_url = "https://accounts.google.com/o/oauth2/v2/auth?scope=https%3A//www.googleapis.com/auth/drive&access_type=offline&include_granted_scopes=true&response_type=code&state=state_parameter_passthrough_value&redirect_uri=" . $google_redirect_url . "&client_id=" . $google_client_id;

$url_components = parse_url($file_url);
$code_in_url = null;
if (isset($url_components["query"])) {
    parse_str($url_components["query"], $params);
    $code_in_url = (isset($params["code"])) ? $params["code"] : false;
}
?>


<!DOCTYPE html>
<html lang="en">

    <head>
        <meta charset="UTF-8">
        <meta http-equiv="X-UA-Compatible" content="IE=edge">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Getting Google Auth Code</title>
        <style>
            body {
                display: flex;
                flex-direction: column;
                justify-content: center;
                align-items: center;
                font-family: "Trebuchet MS" , sans-serif;
                font-size: 16px;
                width: 99vw;
                max-width: 99vw;
                height: 99vh;
                max-height: 99vh;
                text-align: center;
                padding: 16px;
                overflow: hidden;
                overflow-wrap: anywhere;
            }

            .button-copy {
                font-size: 16px;
                padding: 4px 8px;
                border-radius: 4px;
                outline: none;
                border: 1px dashed wheat;
                background-color: transparent;
                color: wheat;
                cursor: pointer;
                transition: all 0.3s;
            }

            .button {
                font-size: 16px;
                font-weight: bold;
                padding: 16px 24px;
                border-radius: 8px;
                outline: none;
                border: 1px solid rgba(0, 0, 0, 0.3);
                box-shadow: 0px 2px 2px rgba(0, 0, 0, 0.3);
                background-color: white;
                cursor: pointer;
                transition: all 0.3s;
            }

            .button:hover {
                border: 1px solid rgba(0, 0, 0, 0.5);
                box-shadow: 0px 1px 2px rgba(0, 0, 0, 0.3);
            }

            .button:active {
                box-shadow: unset;
            }

            .code {
                display: flex;
                justify-content: center;
                align-items: center;
                flex-wrap: wrap;
                background-color: #1b1b1b;
                color: wheat;
                gap: 16px;
                padding: 16px 24px;
                border-radius: 8px;
                font-family: monospace;
            }

            .about {
                margin-top: 160px;
                font-family: monospace;
                color: #6c757d;
            }
        </style>
    </head>

    <body>
        <h1>Get Google API Auth Code</h1>
        <br />
        <br />
        <br />

        <?php if ($code_in_url) {?>

        <div>
            <strong>Your Google API Auth Code Is:</strong>

            <br />
            <br />

            <div class="code">
                <div><code id="toCopy"><?php echo $code_in_url ?></code></div>
                <button class="button-copy" id="copy">Copy</button>
            </div>

            <br />
            <br />

            <strong>Copy & Paste This Auth Code In To The Config File!</strong>
        </div>

        <?php } else {?>

        <div>
            <strong>
                Notice: Before continue make sure you have already done these two:
            </strong>

            <br />

            <strong>1st:</strong>
            Edit this file and add your
            <a href="https://console.cloud.google.com/apis/credentials/" target="_blank">
                Google API Client ID
            </a>
            to the related parameter top of the file codes.

            <br />

            <strong>2nd:</strong>
            Before continue make sure you have already added this file uri to your
            <a href="https://console.cloud.google.com/apis/credentials/" target="_blank">
                Google API Authorized Redirect URIs
            </a>

            <br />
            <br />

            <div class="code">
                This File URI:
                <div><code id="toCopy"><?php echo $file_url ?></code></div>
                <button class="button-copy" id="copy">Copy</button>
            </div>

            <br />
            <br />

            <button class="button" onclick="location.href='<?php echo $google_auth_url ?>'">
                Get Google Auth Code
            </button>
        </div>

        <?php }?>

        <!-- <div class="about">

        </div> -->

        <script>
            if (document.getElementById("copy") && document.getElementById("toCopy")) {
            document.getElementById("copy").addEventListener("click", function(el) {
                let codeStr = document.getElementById("toCopy").textContent.trim();
                navigator.clipboard.writeText(codeStr);
                el.target.textContent = "Copied!";
                setTimeout(function(){el.target.textContent = "Copy"}, 1000);
            })};
        </script>
    </body>

</html>
