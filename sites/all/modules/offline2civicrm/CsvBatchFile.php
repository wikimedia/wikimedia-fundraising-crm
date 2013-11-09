<?php

/**
 * Generic CSV batchfile reader
 */
class CsvBatchFile {
    protected $file;

    function __construct( $filename ) {
        ini_set( 'auto_detect_line_endings', true );
        if( ( $this->file = fopen( $filename, 'r' )) === FALSE ){
            throw new WmfException( 'FILE_NOT_FOUND', 'Could not open file for reading: ' . $filename );
        }

        $this->headers = fgetcsv( $this->file, 0, ',', '"', '\\');
    }

    function read_line() {
        $values = fgetcsv( $this->file, 0, ',', '"', '\\');
        if ( $values === false ) {
            return null;
        } else {
            return array_combine( $this->headers, $values );
        }
    }
}
