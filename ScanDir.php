<?php

class ScanDir
{
    static private $directories, $files, $ext_filter, $recursive, $include_dirs;

    // scan(dirpath::string|array, extensions::string|array, recursive::true|false, include_dirs::true|false)
    // include_dirs adds the directories found to the result:
    //   returns ['files' => [...], 'dirs' => [...]] instead of just the file list.
    static public function scan(){
        // Initialize defaults
        self::$recursive = false;
        self::$directories = array();
        self::$files = array();
        self::$ext_filter = false;
        self::$include_dirs = false;

        // Check we have minimum parameters
        if(!$args = func_get_args()){
            die("Must provide a path string or array of path strings");
        }
        if(gettype($args[0]) != "string" && gettype($args[0]) != "array"){
            die("Must provide a path string or array of path strings");
        }

        // Check if recursive scan | default action: no sub-directories
        if(isset($args[2]) && $args[2] == true){self::$recursive = true;}

        // Check if directories should be collected too | default action: files only
        if(isset($args[3]) && $args[3] == true){self::$include_dirs = true;}

        // Was a filter on file extensions included? | default action: return all file types
        if(isset($args[1])){
            if(gettype($args[1]) == "array"){
                self::$ext_filter = array_map('strtolower', $args[1]);
            } elseif(gettype($args[1]) == "string"){
                self::$ext_filter[] = strtolower($args[1]);
            }
        }

        // Grab path(s)
        self::verifyPaths($args[0]);

        // Include directories in the result when requested.
        if(self::$include_dirs){
            return ['files' => self::$files, 'dirs' => self::$directories];
        }
        return self::$files;
    }

    static private function verifyPaths($paths){
        $path_errors = array();
        if(gettype($paths) == "string"){
            $paths = array($paths);
        }

        foreach($paths as $path){
            if(is_dir($path)){
                // Note: the original also added the scan root itself to
                // $directories here — but that list was never returned, so
                // it was dead code. Now that we DO return directories,
                // don't include the scan root.
                $dirContents = self::find_contents($path);
            } else {
                $path_errors[] = $path;
            }
        }

        if($path_errors){
            echo "The following directories do not exist:\n";
            die(var_dump($path_errors));
        }
    }

    // This is how we scan directories
    static private function find_contents($dir){
        $result = array();
        $root = scandir($dir);
        foreach($root as $value){
            if($value === '.' || $value === '..') {continue;}
            if(is_file($dir.DIRECTORY_SEPARATOR.$value)){
                if(!self::$ext_filter || in_array(strtolower(pathinfo($dir.DIRECTORY_SEPARATOR.$value, PATHINFO_EXTENSION)), self::$ext_filter)){
                    self::$files[] = $result[] = $dir.DIRECTORY_SEPARATOR.$value;
                }
                continue;
            }
            if(self::$include_dirs){
                self::$directories[] = $dir.DIRECTORY_SEPARATOR.$value;
            }
            if(self::$recursive){
                foreach(self::find_contents($dir.DIRECTORY_SEPARATOR.$value) as $value) {
                    self::$files[] = $result[] = $value;
                }
            }
        }
        // Return required for recursive search
        return $result;
    }
}
