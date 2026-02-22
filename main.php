<?php

declare(strict_types=1);

class EdifactGeneratorException extends Exception
{
    private string $errorCode;
    private array $details;

    public function __construct(string $message, string $code = "EDIFACT_001", array $details = [])
    {
        $this->errorCode = $code;
        $this->details = $details;
        parent::__construct("{$code}: {$message}");
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getDetails(): array
    {
        return $this->details;
    }
}

class DecimalHelper
{
    public static function createDecimal(string $value): string
    {
        if (!preg_match('/^-?\d+(\.\d+)?$/', $value)) {
            throw new InvalidArgumentException("Invalid decimal value: {$value}");
        }
        
        return $value;
    }
    
    public static function roundDecimal(string $value, string $precision): string
    {
        $scale = self::getPrecisionScale($precision);
        $rounded = round((float)$value, $scale);
        return number_format($rounded, $scale, '.', '');
    }
    
    private static function getPrecisionScale(string $precision): int
    {
        $parts = explode('.', $precision);
        return isset($parts[1]) ? strlen($parts[1]) : 0;
    }
    
    public static function add(string $a, string $b, int $scale = 2): string
    {
        if (function_exists('bcadd')) {
            return bcadd($a, $b, $scale);
        }
        
        $result = (float)$a + (float)$b;
        return number_format($result, $scale, '.', '');
    }
    
    public static function multiply(string $a, string $b, int $scale = 2): string
    {
        if (function_exists('bcmul')) {
            return bcmul($a, $b, $scale);
        }
        
        $result = (float)$a * (float)$b;
        return number_format($result, $scale, '.', '');
    }
    
    public static function divide(string $a, string $b, int $scale = 2): string
    {
        if (abs((float)$b) < 0.0000001) {
            throw new InvalidArgumentException("Division by zero or very small number");
        }
        
        if (function_exists('bcdiv')) {
            return bcdiv($a, $b, $scale);
        }
        
        $result = (float)$a / (float)$b;
        return number_format($result, $scale, '.', '');
    }
    
    public static function compare(string $a, string $b, int $scale = 2): int
    {
        if (function_exists('bccomp')) {
            return bccomp($a, $b, $scale);
        }
        
        $epsilon = pow(10, -$scale) / 2;
        $diff = abs((float)$a - (float)$b);
        
        if ($diff < $epsilon) {
            return 0;
        }
        
        return (float)$a < (float)$b ? -1 : 1;
    }
    
    public static function formatDecimal(string $value, int $scale = 2): string
    {
        return number_format((float)$value, $scale, '.', '');
    }
    
    public static function formatForCharset(string $value, string $charset, int $scale = 2): string
    {
        $formatted = number_format((float)$value, $scale, '.', '');
        
        if (in_array($charset, ["UNOA", "UNOB"], true)) {
            $formatted = str_replace('.', ',', $formatted);
        }
        
        return $formatted;
    }
    
    public static function validatePrecision(string $value, string $precision): bool
    {
        $scale = self::getPrecisionScale($precision);
        $rounded = round((float)$value, $scale);
        $diff = abs((float)$value - $rounded);
        
        return $diff < (0.5 * pow(10, -$scale));
    }
}

class OrderItem
{
    public string $productCode;
    public string $description;
    public string $quantity;
    public string $price;
    public ?string $unit;

    public function __construct(array $data)
    {
        $this->productCode = (string)$data['product_code'];
        $this->description = (string)($data['description'] ?? '');
        $this->quantity = DecimalHelper::createDecimal((string)$data['quantity']);
        $this->price = DecimalHelper::createDecimal((string)$data['price']);
        $this->unit = isset($data['unit']) ? (string)$data['unit'] : null;
    }
}

class OrderParty
{
    public string $qualifier;
    public string $id;
    public ?string $name;
    public ?string $address;
    public ?string $contact;
    public ?string $contactType;

    public function __construct(array $data)
    {
        $this->qualifier = (string)$data['qualifier'];
        $this->id = (string)$data['id'];
        $this->name = isset($data['name']) ? (string)$data['name'] : null;
        $this->address = isset($data['address']) ? (string)$data['address'] : null;
        $this->contact = isset($data['contact']) ? (string)$data['contact'] : null;
        $this->contactType = isset($data['contact_type']) ? (string)$data['contact_type'] : null;
    }
}

class OrderData
{
    public string $messageRef;
    public string $orderNumber;
    public string $orderDate;
    public array $parties;
    public array $items;
    public ?string $deliveryDate;
    public ?string $currency;
    public ?string $deliveryLocation;
    public ?string $paymentTerms;
    public ?string $taxRate;
    public ?string $specialInstructions;
    public ?string $incoterms;

    public function __construct(array $data)
    {
        $this->messageRef = (string)$data['message_ref'];
        $this->orderNumber = (string)$data['order_number'];
        $this->orderDate = (string)$data['order_date'];
        
        $this->parties = [];
        foreach ($data['parties'] as $partyData) {
            $this->parties[] = new OrderParty($partyData);
        }
        
        $this->items = [];
        foreach ($data['items'] as $itemData) {
            $this->items[] = new OrderItem($itemData);
        }
        
        $this->deliveryDate = isset($data['delivery_date']) ? (string)$data['delivery_date'] : null;
        $this->currency = isset($data['currency']) ? (string)$data['currency'] : null;
        $this->deliveryLocation = isset($data['delivery_location']) ? (string)$data['delivery_location'] : null;
        $this->paymentTerms = isset($data['payment_terms']) ? (string)$data['payment_terms'] : null;
        $this->taxRate = isset($data['tax_rate']) ? DecimalHelper::createDecimal((string)$data['tax_rate']) : null;
        $this->specialInstructions = isset($data['special_instructions']) ? (string)$data['special_instructions'] : null;
        $this->incoterms = isset($data['incoterms']) ? (string)$data['incoterms'] : null;
    }
}

class EdifactConfig
{
    public string $unaSegment = "UNA:+.? '";
    public string $messageType = "ORDERS";
    public string $dateFormat = "102";
    public string $version = "D";
    public string $release = "96A";
    public string $controllingAgency = "UN";
    public string $decimalRounding = "0.01";
    public string $lineEnding = "\n";
    public bool $includeUna = true;
    public string $senderId = "SENDER";
    public string $receiverId = "RECEIVER";
    public string $applicationRef = "";
    public string $ackRequest = "";
    public string $agreementId = "";
    public string $testIndicator = "";
    public string $charset = "UNOC";
    public int $maxSegmentLength = 2000;
    public int $maxFieldLength = 70;
    public array $allowedQualifiers = ["BY", "SU", "DP", "IV", "CB"];

    public const CONTACT_TYPE_TELEPHONE = "TE";
    public const CONTACT_TYPE_EMAIL = "EM";
    public const CONTACT_TYPE_FAX = "FX";
    public const CONTACT_TYPE_ADDRESS = "AD";
    
    public const DTM_QUALIFIER_ORDER_DATE = "137";
    public const DTM_QUALIFIER_DELIVERY_DATE = "2";
    public const DTM_QUALIFIER_PAYMENT_DUE = "12";
    
    public const MOA_LINE_TOTAL = "79";
    public const MOA_TAX_TOTAL = "124";
    public const MOA_INVOICE_TOTAL = "86";

    public function __construct(array $config = [])
    {
        foreach ($config as $key => $value) {
            if (property_exists($this, $key)) {
                $this->{$key} = $value;
            }
        }
        
        $this->validate();
    }
    
    private function validate(): void
    {
        foreach ($this->allowedQualifiers as $qualifier) {
            if (strlen($qualifier) !== 2) {
                throw new InvalidArgumentException("All qualifiers must be 2 characters");
            }
        }
        
        if ($this->maxSegmentLength < 10) {
            throw new InvalidArgumentException("max_segment_length must be at least 10");
        }
        
        if (!in_array($this->charset, ["UNOA", "UNOB", "UNOC"], true)) {
            throw new InvalidArgumentException("Unsupported charset: {$this->charset}");
        }
    }
}

class SegmentGenerator
{
    private const ESCAPE_CHARS = ["'", "+", ":", "?"];
    
    private const DATE_FORMAT_MAP = [
        "102" => "Ymd",
        "203" => "YmdHi",
        "101" => "ymd",
        "204" => "YmdHis"
    ];

    public static function escapeEdifact(?string $value, string $charset = "UNOC"): string
    {
        if ($value === null) {
            return "";
        }
        
        $result = "";
        $length = strlen($value);
        
        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];
            $ord = ord($char);
            
            if ($ord >= 0 && $ord <= 31 || $ord == 127) {
                continue;
            }
            
            if ($char === '.' && in_array($charset, ["UNOA", "UNOB"], true)) {
                $result .= ',';
                continue;
            }
            
            if (in_array($char, self::ESCAPE_CHARS, true)) {
                $result .= '?' . $char;
            } else {
                $result .= $char;
            }
        }
        
        return $result;
    }
    
    public static function validateSegmentLength(string $segment, EdifactConfig $config): void
    {
        if (strlen($segment) > $config->maxSegmentLength) {
            $truncatedSegment = substr($segment, 0, 100);
            throw new EdifactGeneratorException(
                "Segment too long: " . strlen($segment) . " > " . $config->maxSegmentLength,
                "SEGMENT_001",
                ["segment" => $truncatedSegment, "length" => strlen($segment)]
            );
        }
    }
    
    public static function validateDecimalPrecision(string $value, EdifactConfig $config): void
    {
        if (!DecimalHelper::validatePrecision($value, $config->decimalRounding)) {
            throw new EdifactGeneratorException(
                "Decimal value {$value} exceeds configured precision {$config->decimalRounding}",
                "VALID_009"
            );
        }
    }
    
    public static function unb(EdifactConfig $config, string $messageRef): string
    {
        $timestamp = date('ymdHi');
        $elements = [
            "UNOA:2",
            self::escapeEdifact($config->senderId, $config->charset),
            self::escapeEdifact($config->receiverId, $config->charset),
            $timestamp,
            self::escapeEdifact($messageRef, $config->charset)
        ];
        
        if ($config->applicationRef !== "") {
            $elements[] = self::escapeEdifact($config->applicationRef, $config->charset);
        }
        if ($config->ackRequest !== "") {
            $elements[] = self::escapeEdifact($config->ackRequest, $config->charset);
        }
        if ($config->agreementId !== "") {
            $elements[] = self::escapeEdifact($config->agreementId, $config->charset);
        }
        if ($config->testIndicator !== "") {
            $elements[] = self::escapeEdifact($config->testIndicator, $config->charset);
        }
        
        $segment = "UNB+" . implode("+", $elements) . "'";
        self::validateSegmentLength($segment, $config);
        return $segment;
    }
    
    public static function una(EdifactConfig $config): string
    {
        return $config->unaSegment;
    }
    
    public static function unz(int $messageCount, string $messageRef = "", ?EdifactConfig $config = null): string
    {
        if ($messageCount < 1) {
            throw new EdifactGeneratorException("Message count must be at least 1", "UNZ_001");
        }
        
        $config = $config ?? new EdifactConfig();
        $segment = "UNZ+{$messageCount}+" . self::escapeEdifact($messageRef, $config->charset) . "'";
        self::validateSegmentLength($segment, $config);
        return $segment;
    }
    
    public static function unh(string $messageRef, EdifactConfig $config): string
    {
        $segment = "UNH+" . self::escapeEdifact($messageRef, $config->charset) . 
                   "+{$config->messageType}:{$config->version}:{$config->release}:{$config->controllingAgency}'";
        self::validateSegmentLength($segment, $config);
        return $segment;
    }
    
    public static function bgm(string $orderNumber, string $documentType = "220", ?EdifactConfig $config = null): string
    {
        $config = $config ?? new EdifactConfig();
        $segment = "BGM+{$documentType}+" . self::escapeEdifact($orderNumber, $config->charset) . "+9'";
        self::validateSegmentLength($segment, $config);
        return $segment;
    }
    
    public static function dtm(string $qualifier, string $date, string $dateFormat, ?EdifactConfig $config = null): string
    {
        $config = $config ?? new EdifactConfig();
        $segment = "DTM+{$qualifier}:" . self::escapeEdifact($date, $config->charset) . ":{$dateFormat}'";
        self::validateSegmentLength($segment, $config);
        return $segment;
    }
    
    public static function nad(string $qualifier, string $partyId, ?string $name = null, ?EdifactConfig $config = null): string
    {
        $config = $config ?? new EdifactConfig();
        $elements = [
            self::escapeEdifact($qualifier, $config->charset),
            self::escapeEdifact($partyId, $config->charset) . "::91"
        ];
        
        if ($name !== null && $name !== '') {
            if (strlen($name) > $config->maxFieldLength) {
                $name = substr($name, 0, $config->maxFieldLength);
            }
            $elements[] = "";
            $elements[] = self::escapeEdifact($name, $config->charset);
        }
        
        $segment = "NAD+" . implode("+", $elements) . "'";
        self::validateSegmentLength($segment, $config);
        return $segment;
    }
    
    public static function com(string $contact, string $contactType, ?EdifactConfig $config = null): string
    {
        $config = $config ?? new EdifactConfig();
        $segment = "COM+" . self::escapeEdifact($contact, $config->charset) . 
                   ":" . self::escapeEdifact($contactType, $config->charset) . "'";
        self::validateSegmentLength($segment, $config);
        return $segment;
    }
    
    public static function lin(int $lineNum, string $productCode, ?EdifactConfig $config = null): string
    {
        $config = $config ?? new EdifactConfig();
        $segment = "LIN+{$lineNum}++" . self::escapeEdifact($productCode, $config->charset) . ":EN'";
        self::validateSegmentLength($segment, $config);
        return $segment;
    }
    
    public static function imd(string $description, ?EdifactConfig $config = null): string
    {
        $config = $config ?? new EdifactConfig();
        if (strlen($description) > $config->maxFieldLength) {
            $description = substr($description, 0, $config->maxFieldLength);
        }
        $segment = "IMD+F++:::" . self::escapeEdifact($description, $config->charset) . "'";
        self::validateSegmentLength($segment, $config);
        return $segment;
    }
    
    public static function qty(string $quantity, string $unit, ?EdifactConfig $config = null): string
    {
        $config = $config ?? new EdifactConfig();
        self::validateDecimalPrecision($quantity, $config);
        $rounded = DecimalHelper::roundDecimal($quantity, $config->decimalRounding);
        $formatted = DecimalHelper::formatForCharset($rounded, $config->charset);
        $segment = "QTY+21:{$formatted}:" . self::escapeEdifact($unit, $config->charset) . "'";
        self::validateSegmentLength($segment, $config);
        return $segment;
    }
    
    public static function pri(string $price, EdifactConfig $config, string $unit = "EA"): string
    {
        self::validateDecimalPrecision($price, $config);
        $rounded = DecimalHelper::roundDecimal($price, $config->decimalRounding);
        $formatted = DecimalHelper::formatForCharset($rounded, $config->charset);
        $segment = "PRI+AAA:{$formatted}:" . self::escapeEdifact($unit, $config->charset) . "'";
        self::validateSegmentLength($segment, $config);
        return $segment;
    }
    
    public static function moa(string $qualifier, string $amount, EdifactConfig $config): string
    {
        self::validateDecimalPrecision($amount, $config);
        $rounded = DecimalHelper::roundDecimal($amount, $config->decimalRounding);
        $formatted = DecimalHelper::formatForCharset($rounded, $config->charset);
        $segment = "MOA+" . self::escapeEdifact($qualifier, $config->charset) . ":{$formatted}'";
        self::validateSegmentLength($segment, $config);
        return $segment;
    }
    
    public static function tax(string $rate, string $taxType, ?EdifactConfig $config = null): string
    {
        $config = $config ?? new EdifactConfig();
        self::validateDecimalPrecision($rate, $config);
        $rounded = DecimalHelper::roundDecimal($rate, $config->decimalRounding);
        $formatted = DecimalHelper::formatForCharset($rounded, $config->charset);
        $segment = "TAX+7+" . self::escapeEdifact($taxType, $config->charset) . "+++:::{$formatted}'";
        self::validateSegmentLength($segment, $config);
        return $segment;
    }
    
    public static function loc(string $qualifier, string $location, ?EdifactConfig $config = null): string
    {
        $config = $config ?? new EdifactConfig();
        $segment = "LOC+" . self::escapeEdifact($qualifier, $config->charset) . 
                   "+" . self::escapeEdifact($location, $config->charset) . "'";
        self::validateSegmentLength($segment, $config);
        return $segment;
    }
    
    public static function pai(string $terms, ?EdifactConfig $config = null): string
    {
        $config = $config ?? new EdifactConfig();
        $segment = "PAI+" . self::escapeEdifact($terms, $config->charset) . ":3'";
        self::validateSegmentLength($segment, $config);
        return $segment;
    }
    
    public static function tod(string $incoterms, ?EdifactConfig $config = null): string
    {
        $config = $config ?? new EdifactConfig();
        $segment = "TOD+5++" . self::escapeEdifact($incoterms, $config->charset) . "'";
        self::validateSegmentLength($segment, $config);
        return $segment;
    }
    
    public static function unt(int $segmentCount, string $messageRef, ?EdifactConfig $config = null): string
    {
        if ($segmentCount < 1) {
            throw new EdifactGeneratorException("Segment count must be at least 1", "UNT_001");
        }
        
        $config = $config ?? new EdifactConfig();
        $segment = "UNT+{$segmentCount}+" . self::escapeEdifact($messageRef, $config->charset) . "'";
        self::validateSegmentLength($segment, $config);
        return $segment;
    }
    
    public static function ftx(string $text, string $qualifier, int $sequence, ?EdifactConfig $config = null): string
    {
        $config = $config ?? new EdifactConfig();
        if (strlen($text) > $config->maxFieldLength) {
            $text = substr($text, 0, $config->maxFieldLength);
        }
        $segment = "FTX+{$qualifier}+{$sequence}+++" . self::escapeEdifact($text, $config->charset) . "'";
        self::validateSegmentLength($segment, $config);
        return $segment;
    }
    
    public static function cux(string $currency, ?EdifactConfig $config = null): string
    {
        $config = $config ?? new EdifactConfig();
        $segment = "CUX+2:" . self::escapeEdifact($currency, $config->charset) . ":9'";
        self::validateSegmentLength($segment, $config);
        return $segment;
    }
}

class EdifactValidator
{
    private const DATE_FORMATS = [
        "102" => "Ymd",
        "203" => "YmdHi",
        "101" => "ymd",
        "204" => "YmdHis"
    ];

    public static function validateDate(string $dateStr, string $dateFormatCode): bool
    {
        if (!isset(self::DATE_FORMATS[$dateFormatCode])) {
            return false;
        }
        
        $format = self::DATE_FORMATS[$dateFormatCode];
        $dateTime = DateTime::createFromFormat($format, $dateStr);
        
        return $dateTime && $dateTime->format($format) === $dateStr;
    }
    
    public static function validateFieldLength(string $fieldName, string $value, int $maxLength): void
    {
        if (strlen($value) > $maxLength) {
            $truncatedValue = substr($value, 0, 50);
            throw new EdifactGeneratorException(
                "Field '{$fieldName}' exceeds maximum length of {$maxLength}",
                "VALID_014",
                ["field" => $fieldName, "value" => $truncatedValue, "length" => strlen($value)]
            );
        }
    }
    
    public static function validateWithSchema(array $data): void
    {
        $requiredFields = ["message_ref", "order_number", "order_date", "parties", "items"];
        
        foreach ($requiredFields as $field) {
            if (!isset($data[$field])) {
                throw new EdifactGeneratorException(
                    "Missing required field: {$field}",
                    "SCHEMA_001",
                    ["missing_field" => $field]
                );
            }
        }
        
        self::validateFieldLength("message_ref", (string)$data['message_ref'], 14);
        self::validateFieldLength("order_number", (string)$data['order_number'], 35);
        
        if (isset($data['currency']) && $data['currency'] !== '') {
            self::validateFieldLength("currency", (string)$data['currency'], 3);
        }
        
        if (isset($data['delivery_location']) && $data['delivery_location'] !== '') {
            self::validateFieldLength("delivery_location", (string)$data['delivery_location'], 35);
        }
        
        if (isset($data['payment_terms']) && $data['payment_terms'] !== '') {
            self::validateFieldLength("payment_terms", (string)$data['payment_terms'], 35);
        }
        
        if (isset($data['incoterms']) && $data['incoterms'] !== '') {
            self::validateFieldLength("incoterms", (string)$data['incoterms'], 3);
        }
        
        if (!is_array($data['parties'] ?? null) || count($data['parties']) < 2) {
            throw new EdifactGeneratorException(
                "At least 2 parties are required",
                "SCHEMA_002",
                ["parties_count" => count($data['parties'] ?? [])]
            );
        }
        
        foreach ($data['parties'] as $idx => $party) {
            if (!is_array($party)) {
                throw new EdifactGeneratorException(
                    "Party {$idx} must be an array",
                    "SCHEMA_003",
                    ["party_index" => $idx]
                );
            }
            
            if (!isset($party['qualifier']) || !isset($party['id'])) {
                throw new EdifactGeneratorException(
                    "Party {$idx} must contain qualifier and id",
                    "SCHEMA_004",
                    ["party_index" => $idx]
                );
            }
            
            self::validateFieldLength("qualifier", (string)$party['qualifier'], 2);
            self::validateFieldLength("id", (string)$party['id'], 35);
        }
        
        if (!is_array($data['items'] ?? null) || count($data['items']) < 1) {
            throw new EdifactGeneratorException(
                "At least one item is required",
                "SCHEMA_005",
                ["items_count" => count($data['items'] ?? [])]
            );
        }
        
        foreach ($data['items'] as $idx => $item) {
            if (!is_array($item)) {
                throw new EdifactGeneratorException(
                    "Item {$idx} must be an array",
                    "SCHEMA_006",
                    ["item_index" => $idx]
                );
            }
            
            if (!isset($item['product_code']) || !isset($item['quantity']) || !isset($item['price'])) {
                throw new EdifactGeneratorException(
                    "Item {$idx} must contain product_code, quantity, and price",
                    "SCHEMA_007",
                    ["item_index" => $idx]
                );
            }
            
            self::validateFieldLength("product_code", (string)$item['product_code'], 35);
        }
    }
    
    public static function sanitizeInput(array $data): array
    {
        $sanitized = [];
        
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $result = '';
                $length = strlen($value);
                
                for ($i = 0; $i < $length; $i++) {
                    $char = $value[$i];
                    $ord = ord($char);
                    
                    if (!($ord >= 0 && $ord <= 31 || $ord == 127)) {
                        $result .= $char;
                    }
                }
                
                $sanitized[$key] = $result;
            } elseif (is_array($value)) {
                $sanitized[$key] = self::sanitizeInput($value);
            } else {
                $sanitized[$key] = $value;
            }
        }
        
        return $sanitized;
    }
    
    public static function validateOrderData(array $data, EdifactConfig $config): OrderData
    {
        self::validateWithSchema($data);
        
        if (!is_array($data['items']) || count($data['items']) === 0) {
            throw new EdifactGeneratorException("At least one item is required", "VALID_002");
        }
        
        if (!self::validateDate($data['order_date'], $config->dateFormat)) {
            throw new EdifactGeneratorException(
                "Invalid order_date format for {$config->dateFormat}",
                "VALID_003",
                ["date" => $data['order_date'], "format" => $config->dateFormat]
            );
        }
        
        if (isset($data['delivery_date']) && $data['delivery_date'] !== '' && !self::validateDate($data['delivery_date'], $config->dateFormat)) {
            throw new EdifactGeneratorException(
                "Invalid delivery_date format for {$config->dateFormat}",
                "VALID_004",
                ["date" => $data['delivery_date'], "format" => $config->dateFormat]
            );
        }
        
        try {
            foreach ($data['items'] as $idx => $item) {
                if (strlen($item['product_code'] ?? '') > 35) {
                    throw new EdifactGeneratorException(
                        "Product code too long in item {$idx}",
                        "VALID_007",
                        ["item_index" => $idx, "field" => "product_code", "length" => strlen($item['product_code'])]
                    );
                }
                
                $quantity = (float)$item['quantity'];
                if ($quantity <= 0.0) {
                    throw new EdifactGeneratorException(
                        "Item {$idx} quantity must be positive",
                        "VALID_010",
                        ["item_index" => $idx, "quantity" => $item['quantity']]
                    );
                }
                
                $price = (float)$item['price'];
                if ($price < 0.0) {
                    throw new EdifactGeneratorException(
                        "Item {$idx} price must be non-negative",
                        "VALID_011",
                        ["item_index" => $idx, "price" => $item['price']]
                    );
                }
            }
        } catch (Exception $e) {
            throw new EdifactGeneratorException("Invalid numeric format: " . $e->getMessage(), "VALID_005");
        }
        
        $buyerCount = 0;
        $supplierCount = 0;
        
        foreach ($data['parties'] as $idx => $party) {
            if (!isset($party['qualifier']) || !isset($party['id'])) {
                throw new EdifactGeneratorException(
                    "Party {$idx} must contain qualifier and id",
                    "VALID_006",
                    ["party_index" => $idx]
                );
            }
            
            if (!in_array($party['qualifier'], $config->allowedQualifiers, true)) {
                throw new EdifactGeneratorException(
                    "Invalid qualifier '{$party['qualifier']}' in party {$idx}",
                    "VALID_008",
                    ["party_index" => $idx, "qualifier" => $party['qualifier'], "allowed" => $config->allowedQualifiers]
                );
            }
            
            if ($party['qualifier'] === 'BY') {
                $buyerCount++;
            } elseif ($party['qualifier'] === 'SU') {
                $supplierCount++;
            }
        }
        
        if ($buyerCount === 0) {
            throw new EdifactGeneratorException("At least one buyer (BY) party is required", "VALID_012");
        }
        
        if ($supplierCount === 0) {
            throw new EdifactGeneratorException("At least one supplier (SU) party is required", "VALID_013");
        }
        
        $sanitizedData = self::sanitizeInput($data);
        return new OrderData($sanitizedData);
    }
    
    public static function validateMessageStructure(array $segments): bool
    {
        if (empty($segments)) {
            return false;
        }
        
        $firstSegment = $segments[0];
        $lastSegment = $segments[count($segments) - 1];
        
        if (!(strpos($firstSegment, "UNA") === 0 || strpos($firstSegment, "UNB") === 0)) {
            return false;
        }
        
        if (!(strpos($lastSegment, "UNT") === 0 || strpos($lastSegment, "UNZ") === 0)) {
            return false;
        }
        
        $unhCount = 0;
        $untCount = 0;
        
        foreach ($segments as $segment) {
            if (strpos($segment, "UNH+") === 0) $unhCount++;
            if (strpos($segment, "UNT+") === 0) $untCount++;
        }
        
        return $unhCount === $untCount;
    }
}

class SegmentBuilder
{
    private EdifactConfig $config;
    private array $segments = [];
    private ?int $unhIndex = null;

    public function __construct(EdifactConfig $config)
    {
        $this->config = $config;
    }
    
    public function addSegment(string $segment): void
    {
        SegmentGenerator::validateSegmentLength($segment, $this->config);
        $this->segments[] = $segment;
        
        if (strpos($segment, "UNH+") === 0 && $this->unhIndex === null) {
            $this->unhIndex = count($this->segments) - 1;
        }
    }
    
    public function addUna(): void
    {
        if ($this->config->includeUna) {
            array_unshift($this->segments, SegmentGenerator::una($this->config));
            if ($this->unhIndex !== null) {
                $this->unhIndex++;
            }
        }
    }
    
    public function addUnb(string $messageRef): void
    {
        $this->addSegment(SegmentGenerator::unb($this->config, $messageRef));
    }
    
    public function addUnh(string $messageRef): void
    {
        $this->addSegment(SegmentGenerator::unh($messageRef, $this->config));
    }
    
    public function addBgm(string $orderNumber, string $documentType = "220"): void
    {
        $this->addSegment(SegmentGenerator::bgm($orderNumber, $documentType, $this->config));
    }
    
    public function addDtm(string $qualifier, string $date, string $dateFormat): void
    {
        $this->addSegment(SegmentGenerator::dtm($qualifier, $date, $dateFormat, $this->config));
    }
    
    public function addNad(string $qualifier, string $partyId, ?string $name = null): void
    {
        $this->addSegment(SegmentGenerator::nad($qualifier, $partyId, $name, $this->config));
    }
    
    public function addCom(string $contact, string $contactType): void
    {
        $this->addSegment(SegmentGenerator::com($contact, $contactType, $this->config));
    }
    
    public function addLin(int $lineNum, string $productCode): void
    {
        $this->addSegment(SegmentGenerator::lin($lineNum, $productCode, $this->config));
    }
    
    public function addImd(string $description): void
    {
        $this->addSegment(SegmentGenerator::imd($description, $this->config));
    }
    
    public function addQty(string $quantity, string $unit): void
    {
        $this->addSegment(SegmentGenerator::qty($quantity, $unit, $this->config));
    }
    
    public function addPri(string $price, string $unit): void
    {
        $this->addSegment(SegmentGenerator::pri($price, $this->config, $unit));
    }
    
    public function addMoa(string $qualifier, string $amount): void
    {
        $this->addSegment(SegmentGenerator::moa($qualifier, $amount, $this->config));
    }
    
    public function addTax(string $rate, string $taxType): void
    {
        $this->addSegment(SegmentGenerator::tax($rate, $taxType, $this->config));
    }
    
    public function addLoc(string $qualifier, string $location): void
    {
        $this->addSegment(SegmentGenerator::loc($qualifier, $location, $this->config));
    }
    
    public function addPai(string $terms): void
    {
        $this->addSegment(SegmentGenerator::pai($terms, $this->config));
    }
    
    public function addTod(string $incoterms): void
    {
        $this->addSegment(SegmentGenerator::tod($incoterms, $this->config));
    }
    
    public function addFtx(string $text, string $qualifier, int $sequence): void
    {
        $this->addSegment(SegmentGenerator::ftx($text, $qualifier, $sequence, $this->config));
    }
    
    public function addCux(string $currency): void
    {
        $this->addSegment(SegmentGenerator::cux($currency, $this->config));
    }
    
    public function addUnz(int $messageCount, string $messageRef): void
    {
        $this->addSegment(SegmentGenerator::unz($messageCount, $messageRef, $this->config));
    }
    
    public function calculateUntSegmentCount(): int
    {
        if ($this->unhIndex === null) {
            throw new EdifactGeneratorException("UNH segment not found", "UNT_002");
        }
        
        $count = 0;
        for ($i = $this->unhIndex; $i < count($this->segments); $i++) {
            $count++;
            if (strpos($this->segments[$i], "UNT+") === 0) {
                break;
            }
        }
        return $count;
    }
    
    public function addUnt(string $messageRef): void
    {
        $segmentCount = $this->calculateUntSegmentCount();
        $this->addSegment(SegmentGenerator::unt($segmentCount, $messageRef, $this->config));
    }
    
    public function getSegments(): array
    {
        return $this->segments;
    }
    
    public function build(): string
    {
        return implode($this->config->lineEnding, $this->segments);
    }
}

class EdifactAssembler
{
    public static function buildInterchange(OrderData $data, EdifactConfig $config): string
    {
        $builder = new SegmentBuilder($config);
        
        $builder->addUna();
        $builder->addUnb($data->messageRef);
        $builder->addUnh($data->messageRef);
        $builder->addBgm($data->orderNumber);
        $builder->addDtm(EdifactConfig::DTM_QUALIFIER_ORDER_DATE, $data->orderDate, $config->dateFormat);
        
        if ($data->deliveryDate !== null) {
            $builder->addDtm(EdifactConfig::DTM_QUALIFIER_DELIVERY_DATE, $data->deliveryDate, $config->dateFormat);
        }
        
        if ($data->currency !== null) {
            $builder->addCux($data->currency);
        }
        
        foreach ($data->parties as $party) {
            $builder->addNad(
                $party->qualifier,
                $party->id,
                $party->name
            );
            
            if ($party->address !== null) {
                $builder->addCom($party->address, EdifactConfig::CONTACT_TYPE_ADDRESS);
            }
            
            if ($party->contact !== null) {
                $contactType = $party->contactType ?? EdifactConfig::CONTACT_TYPE_TELEPHONE;
                $builder->addCom($party->contact, $contactType);
            }
        }
        
        $totalAmount = "0.00";
        $scale = (int)explode('.', $config->decimalRounding)[1] ?? 2;
        
        foreach ($data->items as $idx => $item) {
            $quantity = $item->quantity;
            $price = $item->price;
            $unit = $item->unit ?? "EA";
            
            $lineTotal = DecimalHelper::multiply($price, $quantity, $scale);
            $lineTotalRounded = DecimalHelper::roundDecimal($lineTotal, $config->decimalRounding);
            
            $builder->addLin($idx + 1, $item->productCode);
            
            if ($item->description !== '') {
                $builder->addImd($item->description);
            }
            
            $builder->addQty($quantity, $unit);
            $builder->addPri($price, $unit);
            
            $totalAmount = DecimalHelper::add($totalAmount, $lineTotalRounded, $scale);
        }
        
        if ($data->taxRate !== null) {
            $taxRate = $data->taxRate;
            $taxAmount = DecimalHelper::divide(
                DecimalHelper::multiply($totalAmount, $taxRate, $scale),
                "100",
                $scale
            );
            
            $taxAmountRounded = DecimalHelper::roundDecimal($taxAmount, $config->decimalRounding);
            
            $builder->addTax($taxRate, "VAT");
            $builder->addMoa(EdifactConfig::MOA_TAX_TOTAL, $taxAmountRounded);
            
            $totalAmount = DecimalHelper::add($totalAmount, $taxAmountRounded, $scale);
        }
        
        if ($data->deliveryLocation !== null) {
            $builder->addLoc("11", $data->deliveryLocation);
        }
        
        if ($data->paymentTerms !== null) {
            $builder->addPai($data->paymentTerms);
        }
        
        if ($data->incoterms !== null) {
            $builder->addTod($data->incoterms);
        }
        
        if ($data->specialInstructions !== null) {
            $instructions = $data->specialInstructions;
            $chunks = str_split($instructions, $config->maxFieldLength);
            
            foreach ($chunks as $i => $chunk) {
                $builder->addFtx($chunk, "AAI", $i + 1);
            }
        }
        
        $builder->addMoa(EdifactConfig::MOA_LINE_TOTAL, $totalAmount);
        
        $builder->addUnt($data->messageRef);
        $builder->addUnz(1, $data->messageRef);
        
        return $builder->build();
    }
}

class EdifactParser
{
    public static function parseOrders(string $edifactContent): array
    {
        $lines = explode("\n", trim($edifactContent));
        $result = [
            "message_ref" => "",
            "order_number" => "",
            "order_date" => "",
            "parties" => [],
            "items" => [],
            "currency" => "",
            "delivery_date" => "",
            "delivery_location" => "",
            "payment_terms" => "",
            "tax_rate" => null,
            "special_instructions" => "",
            "incoterms" => ""
        ];
        
        foreach ($lines as $line) {
            if (strpos($line, "UNH+") === 0) {
                $parts = explode('+', $line);
                if (isset($parts[1])) {
                    $result["message_ref"] = $parts[1];
                }
            } elseif (strpos($line, "BGM+") === 0) {
                $parts = explode('+', $line);
                if (isset($parts[2])) {
                    $result["order_number"] = $parts[2];
                }
            } elseif (strpos($line, "DTM+137:") === 0) {
                $parts = explode(':', $line);
                if (isset($parts[1])) {
                    $result["order_date"] = $parts[1];
                }
            } elseif (strpos($line, "DTM+2:") === 0) {
                $parts = explode(':', $line);
                if (isset($parts[1])) {
                    $result["delivery_date"] = $parts[1];
                }
            } elseif (strpos($line, "CUX+") === 0) {
                $parts = explode(':', $line);
                if (isset($parts[1])) {
                    $result["currency"] = $parts[1];
                }
            } elseif (strpos($line, "NAD+") === 0) {
                $parts = explode('+', $line);
                if (isset($parts[1], $parts[2])) {
                    $qualifier = $parts[1];
                    $partyIdParts = explode(':', $parts[2]);
                    $partyId = $partyIdParts[0] ?? "";
                    $name = $parts[3] ?? "";
                    $result["parties"][] = [
                        "qualifier" => $qualifier,
                        "id" => $partyId,
                        "name" => $name ?: null
                    ];
                }
            } elseif (strpos($line, "LIN+") === 0) {
                $parts = explode('+', $line);
                if (isset($parts[3])) {
                    $productParts = explode(':', $parts[3]);
                    if (isset($productParts[0])) {
                        $result["items"][] = [
                            "product_code" => $productParts[0],
                            "quantity" => "0",
                            "price" => "0",
                            "description" => ""
                        ];
                    }
                }
            }
        }
        
        return $result;
    }
}

class EdifactGenerator
{
    public static function validateFilePath(string $filename, bool $allowSubdirs = true): void
    {
        if (!$allowSubdirs) {
            $safeFilename = basename($filename);
            if ($safeFilename !== $filename) {
                throw new EdifactGeneratorException("Subdirectories not allowed", "IO_002");
            }
        }
        
        if (strpos($filename, '..' . DIRECTORY_SEPARATOR) !== false) {
            throw new EdifactGeneratorException("Path traversal detected", "IO_003");
        }
        
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($extension, ['edi', 'edifact'], true)) {
            error_log("Warning: Recommended file extension is .edi or .edifact");
        }
    }
    
    public static function logGenerationMetrics(array $segments, OrderData $data): void
    {
        $metrics = [
            "segment_count" => count($segments),
            "item_count" => count($data->items),
            "party_count" => count($data->parties),
            "total_chars" => array_sum(array_map('strlen', $segments)),
            "order_number" => $data->orderNumber
        ];
        error_log("EDIFACT generation metrics: " . json_encode($metrics));
    }
    
    public static function generateEdifactOrders(
        array $data,
        EdifactConfig $config,
        ?string $outputFile = null
    ): string {
        error_log("Starting EDIFACT generation for order " . ($data['order_number'] ?? 'Unknown'));
        
        try {
            $validatedData = EdifactValidator::validateOrderData($data, $config);
        } catch (EdifactGeneratorException $e) {
            error_log("Validation failed: {$e->getErrorCode()} - {$e->getMessage()}");
            if (!empty($e->getDetails())) {
                error_log("Details: " . json_encode($e->getDetails()));
            }
            throw $e;
        }
        
        $assembler = new EdifactAssembler();
        $edifactMessage = $assembler->buildInterchange($validatedData, $config);
        
        $segments = explode($config->lineEnding, $edifactMessage);
        $segments = array_filter($segments, fn($s) => $s !== '');
        
        self::logGenerationMetrics($segments, $validatedData);
        
        if (!EdifactValidator::validateMessageStructure($segments)) {
            throw new EdifactGeneratorException("Generated message failed structure validation", "GEN_002");
        }
        
        if ($outputFile !== null) {
            try {
                self::validateFilePath($outputFile, true);
                
                $directory = dirname($outputFile);
                if ($directory !== '.' && $directory !== '' && !is_dir($directory)) {
                    mkdir($directory, 0777, true);
                }
                
                $result = file_put_contents($outputFile, $edifactMessage);
                if ($result === false) {
                    throw new RuntimeException("Failed to write to file");
                }
                
                error_log("EDIFACT message written to {$outputFile}");
            } catch (Exception $e) {
                error_log("Failed to write file: " . $e->getMessage());
                throw new EdifactGeneratorException("File write failed", "IO_001", ["error" => $e->getMessage()]);
            }
        }
        
        return $edifactMessage;
    }
    
    public static function generateEdifactStreaming(
        array $data,
        string $outputFile,
        EdifactConfig $config
    ): void {
        self::validateFilePath($outputFile, true);
        $validatedData = EdifactValidator::validateOrderData($data, $config);
        
        $directory = dirname($outputFile);
        if ($directory !== '.' && $directory !== '' && !is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        
        $handle = fopen($outputFile, 'w');
        if ($handle === false) {
            throw new EdifactGeneratorException("Failed to open file for writing", "IO_004");
        }
        
        $allSegments = [];
        
        try {
            if ($config->includeUna) {
                $segment = SegmentGenerator::una($config);
                fwrite($handle, $segment . $config->lineEnding);
                $allSegments[] = $segment;
            }
            
            $segment = SegmentGenerator::unb($config, $validatedData->messageRef);
            fwrite($handle, $segment . $config->lineEnding);
            $allSegments[] = $segment;
            
            $segment = SegmentGenerator::unh($validatedData->messageRef, $config);
            fwrite($handle, $segment . $config->lineEnding);
            $allSegments[] = $segment;
            
            $segment = SegmentGenerator::bgm($validatedData->orderNumber, "220", $config);
            fwrite($handle, $segment . $config->lineEnding);
            $allSegments[] = $segment;
            
            $segment = SegmentGenerator::dtm(EdifactConfig::DTM_QUALIFIER_ORDER_DATE, $validatedData->orderDate, $config->dateFormat, $config);
            fwrite($handle, $segment . $config->lineEnding);
            $allSegments[] = $segment;
            
            if ($validatedData->deliveryDate !== null) {
                $segment = SegmentGenerator::dtm(EdifactConfig::DTM_QUALIFIER_DELIVERY_DATE, $validatedData->deliveryDate, $config->dateFormat, $config);
                fwrite($handle, $segment . $config->lineEnding);
                $allSegments[] = $segment;
            }
            
            if ($validatedData->currency !== null) {
                $segment = SegmentGenerator::cux($validatedData->currency, $config);
                fwrite($handle, $segment . $config->lineEnding);
                $allSegments[] = $segment;
            }
            
            foreach ($validatedData->parties as $party) {
                $segment = SegmentGenerator::nad(
                    $party->qualifier,
                    $party->id,
                    $party->name,
                    $config
                );
                fwrite($handle, $segment . $config->lineEnding);
                $allSegments[] = $segment;
                
                if ($party->address !== null) {
                    $segment = SegmentGenerator::com($party->address, EdifactConfig::CONTACT_TYPE_ADDRESS, $config);
                    fwrite($handle, $segment . $config->lineEnding);
                    $allSegments[] = $segment;
                }
                
                if ($party->contact !== null) {
                    $contactType = $party->contactType ?? EdifactConfig::CONTACT_TYPE_TELEPHONE;
                    $segment = SegmentGenerator::com($party->contact, $contactType, $config);
                    fwrite($handle, $segment . $config->lineEnding);
                    $allSegments[] = $segment;
                }
            }
            
            $totalAmount = "0.00";
            $scale = (int)explode('.', $config->decimalRounding)[1] ?? 2;
            
            foreach ($validatedData->items as $idx => $item) {
                $quantity = $item->quantity;
                $price = $item->price;
                $unit = $item->unit ?? "EA";
                
                $lineTotal = DecimalHelper::multiply($price, $quantity, $scale);
                $lineTotalRounded = DecimalHelper::roundDecimal($lineTotal, $config->decimalRounding);
                
                $segment = SegmentGenerator::lin($idx + 1, $item->productCode, $config);
                fwrite($handle, $segment . $config->lineEnding);
                $allSegments[] = $segment;
                
                if ($item->description !== '') {
                    $segment = SegmentGenerator::imd($item->description, $config);
                    fwrite($handle, $segment . $config->lineEnding);
                    $allSegments[] = $segment;
                }
                
                $segment = SegmentGenerator::qty($quantity, $unit, $config);
                fwrite($handle, $segment . $config->lineEnding);
                $allSegments[] = $segment;
                
                $segment = SegmentGenerator::pri($price, $config, $unit);
                fwrite($handle, $segment . $config->lineEnding);
                $allSegments[] = $segment;
                
                $totalAmount = DecimalHelper::add($totalAmount, $lineTotalRounded, $scale);
            }
            
            if ($validatedData->taxRate !== null) {
                $taxRate = $validatedData->taxRate;
                $taxAmount = DecimalHelper::divide(
                    DecimalHelper::multiply($totalAmount, $taxRate, $scale),
                    "100",
                    $scale
                );
                
                $taxAmountRounded = DecimalHelper::roundDecimal($taxAmount, $config->decimalRounding);
                
                $segment = SegmentGenerator::tax($taxRate, "VAT", $config);
                fwrite($handle, $segment . $config->lineEnding);
                $allSegments[] = $segment;
                
                $segment = SegmentGenerator::moa(EdifactConfig::MOA_TAX_TOTAL, $taxAmountRounded, $config);
                fwrite($handle, $segment . $config->lineEnding);
                $allSegments[] = $segment;
                
                $totalAmount = DecimalHelper::add($totalAmount, $taxAmountRounded, $scale);
            }
            
            if ($validatedData->deliveryLocation !== null) {
                $segment = SegmentGenerator::loc("11", $validatedData->deliveryLocation, $config);
                fwrite($handle, $segment . $config->lineEnding);
                $allSegments[] = $segment;
            }
            
            if ($validatedData->paymentTerms !== null) {
                $segment = SegmentGenerator::pai($validatedData->paymentTerms, $config);
                fwrite($handle, $segment . $config->lineEnding);
                $allSegments[] = $segment;
            }
            
            if ($validatedData->incoterms !== null) {
                $segment = SegmentGenerator::tod($validatedData->incoterms, $config);
                fwrite($handle, $segment . $config->lineEnding);
                $allSegments[] = $segment;
            }
            
            if ($validatedData->specialInstructions !== null) {
                $instructions = $validatedData->specialInstructions;
                $chunks = str_split($instructions, $config->maxFieldLength);
                
                foreach ($chunks as $i => $chunk) {
                    $segment = SegmentGenerator::ftx($chunk, "AAI", $i + 1, $config);
                    fwrite($handle, $segment . $config->lineEnding);
                    $allSegments[] = $segment;
                }
            }
            
            $segment = SegmentGenerator::moa(EdifactConfig::MOA_LINE_TOTAL, $totalAmount, $config);
            fwrite($handle, $segment . $config->lineEnding);
            $allSegments[] = $segment;
            
            $unhIndex = null;
            foreach ($allSegments as $i => $segment) {
                if (strpos($segment, "UNH+") === 0) {
                    $unhIndex = $i;
                    break;
                }
            }
            
            if ($unhIndex === null) {
                throw new EdifactGeneratorException("UNH segment missing", "GEN_001");
            }
            
            $segmentCount = count($allSegments) - $unhIndex;
            
            $segment = SegmentGenerator::unt($segmentCount, $validatedData->messageRef, $config);
            fwrite($handle, $segment . $config->lineEnding);
            $allSegments[] = $segment;
            
            $segment = SegmentGenerator::unz(1, $validatedData->messageRef, $config);
            fwrite($handle, $segment . $config->lineEnding);
            
            error_log("Streaming generation completed: {$outputFile}");
        } finally {
            fclose($handle);
        }
    }
}

class EdifactBatchGenerator
{
    public static function generateBatchOrders(array $orders, EdifactConfig $config): string
    {
        $interchangeRef = "BATCH" . date('YmdHis');
        $builder = new SegmentBuilder($config);
        
        $builder->addUna();
        $builder->addUnb($interchangeRef);
        
        foreach ($orders as $orderData) {
            $validatedData = EdifactValidator::validateOrderData($orderData, $config);
            $assembler = new EdifactAssembler();
            $message = $assembler->buildInterchange($validatedData, $config);
            
            $messageLines = explode($config->lineEnding, $message);
            $messageLines = array_filter($messageLines, fn($line) => $line !== '');
            
            foreach ($messageLines as $line) {
                if (!(strpos($line, "UNA") === 0 || 
                      strpos($line, "UNB") === 0 || 
                      strpos($line, "UNZ") === 0)) {
                    $builder->addSegment($line);
                }
            }
        }
        
        $builder->addUnz(count($orders), $interchangeRef);
        
        return $builder->build();
    }
}

if (PHP_SAPI === 'cli') {
    $sampleOrder = [
        "message_ref" => "ORD0001",
        "order_number" => "2025-0509-A",
        "order_date" => date('Ymd'),
        "parties" => [
            [
                "qualifier" => "BY",
                "id" => "1234567890123",
                "name" => "Buyer Corp",
                "contact" => "+123456789",
                "contact_type" => EdifactConfig::CONTACT_TYPE_TELEPHONE
            ],
            [
                "qualifier" => "SU",
                "id" => "3210987654321",
                "name" => "Supplier Inc",
                "address" => "Industrial Park",
                "contact" => "supplier@example.com",
                "contact_type" => EdifactConfig::CONTACT_TYPE_EMAIL
            ],
        ],
        "items" => [
            [
                "product_code" => "ITEM001",
                "description" => "Widget A",
                "quantity" => "10.00",
                "price" => "12.50",
                "unit" => "EA"
            ],
            [
                "product_code" => "ITEM002",
                "description" => "Widget B",
                "quantity" => "5.00",
                "price" => "25.00",
                "unit" => "EA"
            ],
        ],
        "delivery_date" => date('Ymd', strtotime('+7 days')),
        "currency" => "USD",
        "delivery_location" => "WAREHOUSE1",
        "payment_terms" => "NET30",
        "tax_rate" => "7.50",
        "special_instructions" => "Please deliver during business hours 9AM-5PM. Contact John Doe at extension 123 for delivery coordination.",
        "incoterms" => "FOB"
    ];
    
    $enhancedConfig = new EdifactConfig([
        "version" => "D",
        "release" => "96A",
        "controllingAgency" => "UN",
        "lineEnding" => "\r\n",
        "senderId" => "BUYER123",
        "receiverId" => "SUPPLIER456",
        "applicationRef" => "ORDERS_APP",
        "ackRequest" => "1",
        "testIndicator" => "0",
        "charset" => "UNOC",
        "maxFieldLength" => 70,
        "maxSegmentLength" => 2000
    ]);
    
    try {
        $message = EdifactGenerator::generateEdifactOrders(
            $sampleOrder,
            $enhancedConfig,
            "output/orders.edi"
        );
        
        echo "\nGenerated EDIFACT ORDERS:\n" . $message . "\n";
        
        $parsed = EdifactParser::parseOrders($message);
        echo "\nParsed order data:\n" . json_encode($parsed, JSON_PRETTY_PRINT) . "\n";
        
        echo "\nTesting streaming generation...\n";
        EdifactGenerator::generateEdifactStreaming($sampleOrder, "output/orders_stream.edi", $enhancedConfig);
        echo "Streaming generation completed\n";
        
        echo "\nTesting batch generation...\n";
        $batchOrders = [$sampleOrder, $sampleOrder];
        $batchMessage = EdifactBatchGenerator::generateBatchOrders($batchOrders, $enhancedConfig);
        echo "Batch message generated with " . count($batchOrders) . " orders\n";
        
    } catch (EdifactGeneratorException $e) {
        echo "Generation failed: {$e->getErrorCode()} - {$e->getMessage()}\n";
        if (!empty($e->getDetails())) {
            echo "Error details: " . json_encode($e->getDetails(), JSON_PRETTY_PRINT) . "\n";
        }
    }
}
