<?php

/** TODO: Use a generalized access wrapper instead. */
class ChecksFileProbe extends ChecksFile {
    function _parseRow( $data ) {
        return $this->parseRow( $data );
    }

	protected function getRequiredColumns() {
		return array();
	}

	protected function getRequiredData() {
		return array();
	}
}
