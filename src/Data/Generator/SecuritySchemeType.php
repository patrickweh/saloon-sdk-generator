<?php

namespace Crescat\SaloonSdkGenerator\Data\Generator;

enum SecuritySchemeType: string
{
    case apiKey = 'apiKey';
    case http = 'http';
    case mutualTLS = 'mutualTLS';
    case oauth2 = 'oauth2';
    case openIdConnect = 'openIdConnect';
}
