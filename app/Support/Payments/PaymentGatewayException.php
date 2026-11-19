<?php

namespace App\Support\Payments;

use RuntimeException;

/** The payment provider failed or refused a request. */
final class PaymentGatewayException extends RuntimeException {}
