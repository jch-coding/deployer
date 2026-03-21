<?php

namespace App;

enum ClassicBaseUrl : string
{
    case US1 = 'https://app1-apigw.central.arubanetworks.com/';
    case US2 = 'https://apigw-prod2.central.arubanetworks.com/';
    case US_EAST1 = 'https://apigw-us-east-1.central.arubanetworks.com/';
    case US_WEST4 = 'https://apigw-uswest4.central.arubanetworks.com/';
    case US_WEST5 = 'https://apigw-uswest5.central.arubanetworks.com/';
    case EU1 = 'https://eu-apigw.central.arubanetworks.com/';
    case EU_CENTRAL2 = 'https://apigw-eucentral2.central.arubanetworks.com/';
    case EU_CENTRAL3 = 'https://apigw-eucentral3.central.arubanetworks.com/';
    case CANADA1 = 'https://apigw-ca.central.arubanetworks.com/';
    case CHINA1 = 'https://apigw.central.arubanetworks.com.cn';
    case APAC1 = 'https://apigw-ap.central.arubanetworks.com/';
    case APAC_EAST1 = 'https://apigw-apaceast.central.arubanetworks.com/';
    case APAC_SOUTH1 = 'https://apigw-apsouth.central.arubanetworks.com/';
    case UAE_NORTH1 = 'https://apigw-uaenorth1.central.arubanetworks.com/';
}
