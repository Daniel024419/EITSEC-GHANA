<?php
declare(strict_types=1);

namespace ZipStream\Exception;

use ZipStream\Exception;

/**
 * This Exception gets invoked if `fread` fails on a stream.
 */
class StreamNotReadableException extends Exception
{
    /**
     * Constructor of the Exception
     *
     * @param string $filename - The name of the file which the stream belongs to.
     */
    public function __construct(string $filename)
    {
        parent::__construct("The stream for $filename could not be read.");
    }
}
