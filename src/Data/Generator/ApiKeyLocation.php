<?php

namespace Crescat\SaloonSdkGenerator\Data\Generator;

enum ApiKeyLocation: string
{
    case cookie = 'cookie';
    case header = 'header';
    case query = 'query';
}
