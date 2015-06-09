<?php

namespace RA\OxidCleanupScripts;


class RecursiveMetadataFilterIterator extends \RecursiveFilterIterator
{

    public static $FILTERS = array(
        'metadata.php',
    );

    public function accept()
    {
        return !in_array(
            $this->current()->getFilename(),
            self::$FILTERS,
            true
        );
    }
}