<?php

namespace Spatie\MediaLibrary\Support\filenamer;

use Spatie\MediaLibrary\Conversions\Conversion;

class Defaultfilenamer extends filenamer
{
    public function conversionfilename(string $filename, Conversion $conversion): string
    {
        $strippedfilename = pathinfo($filename, PATHINFO_filename);

        return "{$strippedfilename}-{$conversion->getName()}";
    }

    public function responsivefilename(string $filename): string
    {
        return pathinfo($filename, PATHINFO_filename);
    }
}
