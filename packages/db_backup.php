<?php
/**
 * Database Backup To Google Drive
 *
 * @copyright 2022 dominusmmp
 * @license MIT
 * 
 */

class DatabaseBackup
{
    // Database Connection Handler
    private $db;

    // Database Connection Data
    private $host, $user, $pass, $dbname;

    // Database Dumping Data
    private $sql_data;

    // Database Tables
    private $tables = array();

    // Erro Messages List
    private $error = array();

    /**
     * Class DatabaseBackup Constructor
     * @example
     * $db = new DatabaseBackup(array(
     * "host" => "",
     * "user" => "",
     * "pass" => "",
     * "dbname" => ""
     * ));
     */
    public function __construct($args)
    {
        // Check If Required Arguments Are Empty
        if (empty($args["host"])) {
            $this->error[] = "Argument host is missing!";
        }

        if (empty($args["user"])) {
            $this->error[] = "Argument user is missing!";
        }

        if (empty($args["pass"])) {
            $this->error[] = "Argument pass is missing!";
        }

        if (empty($args["dbname"])) {
            $this->error[] = "Argument dbname is missing!";
        }

        if (count($this->error) > 0) {
            foreach ($this->error as $err) {echo $err . nl2br("\n\n");}
            die;
        }

        $this->host = $args["host"];
        $this->user = $args["user"];
        $this->pass = $args["pass"];
        $this->dbname = $args["dbname"];
    }

    private function sql_data_concat($str = "")
    {
        $this->sql_data .= $str;
    }

    /**
     * Connect To Database
     */
    private function connect_db()
    {
        try {
            $this->db = new PDO("mysql:host=" . $this->host . ";dbname=" . $this->dbname . ";charset=utf8mb4", $this->user, $this->pass, array(PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES UTF8MB4"));
            $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $e) {
            $this->db = null;
            echo "Connection failed: " . $e->getMessage();
            die;
        }
    }

    /**
     * Get Table Columns
     */
    private function get_columns($table_name)
    {
        try {
            $stmt = $this->db->query("SHOW CREATE TABLE `" . $table_name . "`");
            $q = $stmt->fetchAll();

            $q[0][1] = preg_replace("/AUTO_INCREMENT=[\w]*./", "", $q[0][1]);

            return $q[0][1];

        } catch (PDOException $e) {
            $this->db = null;
            echo $e->getMessage();
            die;
        }
    }

    /**
     * Get Table Data
     */
    private function get_data($table_name)
    {
        try {
            $stmt = $this->db->query("SELECT * FROM `" . $table_name . "`");
            $q = $stmt->fetchAll(PDO::FETCH_NUM);
            $data = "";

            foreach ($q as $pieces) {
                $i = 0;
                foreach ($pieces as $value) {
                    $pieces[$i] = str_replace("\n", "\\n", addslashes($value));
                    $i++;
                }
                $data .= "INSERT INTO `" . $table_name . "` VALUES ('" . implode("', '", $pieces) . "');\n";
            }

            return $data;

        } catch (PDOException $e) {
            $this->db = null;
            echo $e->getMessage();
            die;
        }
    }

    /**
     * Get Database Tables
     */
    private function get_tables()
    {
        try {
            $stmt = $this->db->query("SHOW TABLES");
            $tbs = $stmt->fetchAll();
            $i = 0;

            foreach ($tbs as $table) {
                $this->tables[$i]["name"] = $table[0];
                $this->tables[$i]["columns"] = $this->get_columns($table[0]);
                $this->tables[$i]["data"] = $this->get_data($table[0]);
                $i++;
            }

            unset($stmt);
            unset($tbs);
            unset($i);

            return true;

        } catch (PDOException $e) {
            $this->db = null;
            echo $e->getMessage();
            die;
        }
    }

    /**
     * Get Backup String
     */
    private function get_backup_str()
    {
        $timezone = "UTC";
        date_default_timezone_set("UTC");
        $backup_date = date("Y-m-d");
        $backup_time = date("H:i:s");

        $this->sql_data_concat("--\n-- BACKUP DATE (" . $timezone . "): " . $backup_date . "\n-- BACKUP TIME (" . $timezone . "): " . $backup_time . "\n--\n\n");
        $this->sql_data_concat("--\n-- DATABASE: `" . $this->dbname . "`\n--\n\n");

        foreach ($this->tables as $table) {
            $this->sql_data_concat("--\n-- --------------------------------------------------------\n--\n\n");

            $this->sql_data_concat("--\n-- TABLE STRUCTURE FOR TABLE `" . $table["name"] . "`\n--\n\n");
            $this->sql_data_concat($table["columns"] . ";\n\n");

            if ($table["data"]) {
                $this->sql_data_concat("--\n-- INSERTING DATA INTO TABLE `" . $table["name"] . "`\n--\n\n");
                $this->sql_data_concat($table["data"] . "\n\n");
            }
        }

        $this->sql_data_concat("--\n-- THE END\n--\n");
    }

    /**
     * Save Backup File
     */
    private function save_backup($args)
    {
        // Check If Required Arguments Are Empty
        if (empty($args["path"])) {
            $this->error[] = "Argument 'path' is missing!";
        }

        if (empty($args["filename"])) {
            $this->error[] = "Argument 'filename' is missing!";
        }

        if (count($this->error) > 0) {
            foreach ($this->error as $err) {echo $err . nl2br("\n\n");}
            die;
        }

        $path = (substr($args["path"], -1) != "/") ? $args["path"] : substr($args["path"], 0, -1);
        $filename = $args["filename"];

        if ($args["compression"]) {
            $zip = gzopen($path . "/" . $filename . ".sql.gz", "a9");
            gzwrite($zip, $this->sql_data);
            gzclose($zip);
        } else {
            $file = fopen($path . "/" . $filename . ".sql", "a+");
            fwrite($file, $this->sql_data);
            fclose($file);
        }
    }

    /**
     * Database Backup Function
     * @example
     * backup(array(
     * "path" => "",
     * "filename" => "",
     * "compression" => "",
     * "get_data" => "", // If This Value Set To True, No Need To Pass Other Values Except 'compression', Since They Will Be Ignored
     * ));
     */
    public function backup($args)
    {
        // Check If Required Arguments Are Empty
        if (empty($args)) {
            $this->error[] = "Arguments are missing!";
        }

        if (!is_array($args)) {
            $this->error[] = "Arguments should pass in an array format!";
        }

        if (count($this->error) > 0) {
            foreach ($this->error as $err) {echo $err . nl2br("\n\n");}
            die;
        }

        if (!empty($args["get_data"])) {
            $args["get_data"] = ($args["get_data"] === true) ? true : false;
        } else {
            $args["get_data"] = false;
        }

        if (!empty($args["compression"])) {
            $args["compression"] = ($args["compression"] === true) ? true : false;
        } else {
            $args["compression"] = false;
        }

        $this->connect_db();
        $this->get_tables();
        $this->get_backup_str();

        if ($args["get_data"]) {
            // Return The Compressed Result Data If 'compression' Is True Or The Fresh Result Data
            if ($args["compression"]) {
                return gzencode($this->sql_data, 9);
            } else {
                return $this->sql_data;
            }
        } else {
            $this->save_backup($args);
        }
    }
}
