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
    public static function createDecimal(string $value): float
    {
        if (!preg_match('/^-?\d+(\.\d+)?$/', $value)) {
            throw new InvalidArgumentException("Invalid decimal value: {$value}");
        }
        
        return (float)$value;
    }
    
    public static function roundDecimal(float $value, string $precision): string
    {
        $precisionParts = explode('.', $precision);
        $scale = isset($precisionParts[1]) ? strlen($precisionParts[1]) : 0;
        
        $rounded = round($value, $scale);
        
        if ($scale > 0) {
            return number_format($rounded, $scale, '.', '');
        }
        
        return (string)$rounded;
    }
    
    public static function compare(float $a, float $b): int
    {
        $epsilon = 0.0000001;
        $diff = abs($a - $b);
        
        if ($diff < $epsilon) {
            return 0;
        }
        
        return $a < $b ? -1 : 1;
    }
    
    public static function add(float $a, float $b): float
    {
        return $a + $b;
    }
    
    public static function multiply(float $a, float $b): float
    {
        return $a * $b;
    }
    
    public static function divide(float $a, float $b): float
    {
        if (abs($b) < 0.0000001) {
            throw new InvalidArgumentException("Division by zero or very small number");
        }
        
        return $a / $b;
    }
    
    public static function formatDecimal(float $value, int $scale = 2): string
    {
        return number_format($value, $scale, '.', '');
    }
    
    public static function validatePrecision(float $value, string $precision): bool
    {
        $precisionFloat = (float)$precision;
        $precisionScale = isset(explode('.', $precision)[1]) ? strlen(explode('.', $precision)[1]) : 0;
        
        $rounded = round($value, $precisionScale);
        $diff = abs($value - $rounded);
        
        return $diff < (0.5 * pow(10, -$precisionScale));
    }
}

class OrderItem
{
    public string $productCode;
    public string $description;
    public float $quantity;
    public float $price;
    public ?string $unit;

    public function __construct(array $data)
    {
        $this->productCode = (string)$data['product_code'];
        $this->description = (string)($data['description'] ?? '');
        $this->quantity = (float)$data['quantity'];
        $this->price = (float)$data['price'];
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

    public function __construct(array $data)
    {
        $this->qualifier = (string)$data['qualifier'];
        $this->id = (string)$data['id'];
        $this->name = isset($data['name']) ? (string)$data['name'] : null;
        $this->address = isset($data['address']) ? (string)$data['address'] : null;
        $this->contact = isset($data['contact']) ? (string)$data['contact'] : null;
    }
}

class OrderData
{
    public string $messageRef;
    public string $orderNumber;
    public string $orderDate;
    /** @var OrderParty[] */
    public array $parties;
    /** @var OrderItem[] */
    public array $items;
    public ?string $deliveryDate;
    public ?string $currency;
    public ?string $deliveryLocation;
    public ?string $paymentTerms;
    public ?float $taxRate;
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
        $this->taxRate = isset($data['tax_rate']) ? (float)$data['tax_rate'] : null;
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
    public int $maxSegmentLength = 2000;
    public int $maxFieldLength = 70;
    /** @var string[] */
    public array $allowedQualifiers = ["BY", "SU", "DP", "IV", "CB"];

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
    }
}

class SegmentGenerator
{
    private const ESCAPE_CHARS = ["'", "+", ":"];
    
    public static function escapeEdifact(?string $value): string
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
            
            if ($char === '?') {
                $result .= '??';
            } elseif (in_array($char, self::ESCAPE_CHARS, true)) {
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
    
    public static function validateDecimalPrecision(float $value, EdifactConfig $config): void
    {
        if (!DecimalHelper::validatePrecision($value, $config->decimalRounding)) {
            $formattedValue = DecimalHelper::formatDecimal($value, 6);
            throw new EdifactGeneratorException(
                "Decimal value {$formattedValue} exceeds configured precision {$config->decimalRounding}",
                "VALID_009"
            );
        }
    }
    
    public static function unb(EdifactConfig $config, string $messageRef): string
    {
        $timestamp = date('ymdHi');
        $segment = "UNB+UNOA:2+" . self::escapeEdifact($config->senderId) . "+" . 
                  self::escapeEdifact($config->receiverId) . "+" . $timestamp . "+" . 
                  self::escapeEdifact($messageRef) . "'";
        self::validateSegmentLength($segment, $config);
        return $segment;
    }
    
    public static function una(EdifactConfig $config): string
    {
        return $config->unaSegment;
    }
    
    public static function unz(int $messageCount = 1, string $messageRef = "", ?EdifactConfig $config = null): string
    {
        $segment = "UNZ+{$messageCount}+" . self::escapeEdifact($messageRef) . "'";
        if ($config !== null) {
            self::validateSegmentLength($segment, $config);
        }
        return $segment;
    }
    
    public static function unh(string $messageRef, EdifactConfig $config): string
    {
        $segment = "UNH+" . self::escapeEdifact($messageRef) . "+{$config->messageType}:{$config->version}:{$config->release}:{$config->controllingAgency}'";
        self::validateSegmentLength($segment, $config);
        return $segment;
    }
    
    public static function bgm(string $orderNumber, string $documentType = "220", ?EdifactConfig $config = null): string
    {
        $config = $config ?? new EdifactConfig();
        $segment = "BGM+{$documentType}+" . self::escapeEdifact($orderNumber) . "+9'";
        self::validateSegmentLength($segment, $config);
        return $segment;
    }
    
    public static function dtm(string $qualifier, string $date, string $dateFormat, ?EdifactConfig $config = null): string
    {
        $config = $config ?? new EdifactConfig();
        $segment = "DTM+{$qualifier}:" . self::escapeEdifact($date) . ":{$dateFormat}'";
        self::validateSegmentLength($segment, $config);
        return $segment;
    }
    
    public static function nad(string $qualifier, string $partyId, ?string $name = null, ?EdifactConfig $config = null): string
    {
        $config = $config ?? new EdifactConfig();
        $base = "NAD+" . self::escapeEdifact($qualifier) . "+" . self::escapeEdifact($partyId) . "::91";
        
        if ($name !== null && $name !== '') {
            if (strlen($name) > $config->maxFieldLength) {
                $name = substr($name, 0, $config->maxFieldLength);
            }
            $segment = $base . "++" . self::escapeEdifact($name) . "'";
        } else {
            $segment = $base . "'";
        }
        
        self::validateSegmentLength($segment, $config);
        return $segment;
    }
    
    public static function com(string $contact, string $contactType = "TE", ?EdifactConfig $config = null): string
    {
        $config = $config ?? new EdifactConfig();
        $segment = "COM+" . self::escapeEdifact($contact) . ":" . self::escapeEdifact($contactType) . "'";
        self::validateSegmentLength($segment, $config);
        return $segment;
    }
    
    public static function lin(int $lineNum, string $productCode, ?EdifactConfig $config = null): string
    {
        $config = $config ?? new EdifactConfig();
        $segment = "LIN+{$lineNum}++" . self::escapeEdifact($productCode) . ":EN'";
        self::validateSegmentLength($segment, $config);
        return $segment;
    }
    
    public static function imd(string $description, ?EdifactConfig $config = null): string
    {
        $config = $config ?? new EdifactConfig();
        if (strlen($description) > $config->maxFieldLength) {
            $description = substr($description, 0, $config->maxFieldLength);
        }
        $segment = "IMD+F++:::" . self::escapeEdifact($description) . "'";
        self::validateSegmentLength($segment, $config);
        return $segment;
    }
    
    public static function qty(float $quantity, string $unit = "EA", ?EdifactConfig $config = null): string
    {
        $config = $config ?? new EdifactConfig();
        self::validateDecimalPrecision($quantity, $config);
        $q = DecimalHelper::roundDecimal($quantity, $config->decimalRounding);
        $segment = "QTY+21:{$q}:" . self::escapeEdifact($unit) . "'";
        self::validateSegmentLength($segment, $config);
        return $segment;
    }
    
    public static function pri(float $price, EdifactConfig $config, string $unit = "EA"): string
    {
        self::validateDecimalPrecision($price, $config);
        $q = DecimalHelper::roundDecimal($price, $config->decimalRounding);
        $segment = "PRI+AAA:{$q}:" . self::escapeEdifact($unit) . "'";
        self::validateSegmentLength($segment, $config);
        return $segment;
    }
    
    public static function moa(string $qualifier, float $amount, EdifactConfig $config): string
    {
        self::validateDecimalPrecision($amount, $config);
        $q = DecimalHelper::roundDecimal($amount, $config->decimalRounding);
        $segment = "MOA+" . self::escapeEdifact($qualifier) . ":{$q}'";
        self::validateSegmentLength($segment, $config);
        return $segment;
    }
    
    public static function tax(float $rate, string $taxType = "VAT", ?EdifactConfig $config = null): string
    {
        $config = $config ?? new EdifactConfig();
        self::validateDecimalPrecision($rate, $config);
        $fmtRate = DecimalHelper::roundDecimal($rate, $config->decimalRounding);
        $segment = "TAX+7+" . self::escapeEdifact($taxType) . "+++:::" . $fmtRate . "'";
        self::validateSegmentLength($segment, $config);
        return $segment;
    }
    
    public static function loc(string $qualifier, string $location, ?EdifactConfig $config = null): string
    {
        $config = $config ?? new EdifactConfig();
        $segment = "LOC+" . self::escapeEdifact($qualifier) . "+" . self::escapeEdifact($location) . ":92'";
        self::validateSegmentLength($segment, $config);
        return $segment;
    }
    
    public static function pai(string $terms, ?EdifactConfig $config = null): string
    {
        $config = $config ?? new EdifactConfig();
        $segment = "PAI+" . self::escapeEdifact($terms) . ":3'";
        self::validateSegmentLength($segment, $config);
        return $segment;
    }
    
    public static function tod(string $incoterms, ?EdifactConfig $config = null): string
    {
        $config = $config ?? new EdifactConfig();
        $segment = "TOD+5++" . self::escapeEdifact($incoterms) . "'";
        self::validateSegmentLength($segment, $config);
        return $segment;
    }
    
    public static function unt(int $segmentCount, string $messageRef, ?EdifactConfig $config = null): string
    {
        $config = $config ?? new EdifactConfig();
        $segment = "UNT+{$segmentCount}+" . self::escapeEdifact($messageRef) . "'";
        self::validateSegmentLength($segment, $config);
        return $segment;
    }
    
    public static function ftx(string $text, string $qualifier = "AAI", int $sequence = 1, ?EdifactConfig $config = null): string
    {
        $config = $config ?? new EdifactConfig();
        if (strlen($text) > $config->maxFieldLength) {
            $text = substr($text, 0, $config->maxFieldLength);
        }
        $segment = "FTX+{$qualifier}+{$sequence}+++" . self::escapeEdifact($text) . "'";
        self::validateSegmentLength($segment, $config);
        return $segment;
    }
    
    public static function cux(string $currency, ?EdifactConfig $config = null): string
    {
        $config = $config ?? new EdifactConfig();
        $segment = "CUX+2:" . self::escapeEdifact($currency) . ":9'";
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

    public static function validateDate(string $dateStr, string $dateFormat): bool
    {
        if (!isset(self::DATE_FORMATS[$dateFormat])) {
            return false;
        }
        
        $format = self::DATE_FORMATS[$dateFormat];
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
}

class EdifactGenerator
{
    public static function validateFilePath(string $filename): void
    {
        $safeFilename = basename($filename);
        if ($safeFilename !== $filename) {
            throw new EdifactGeneratorException("Invalid filename provided", "IO_002");
        }
        
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($extension, ['edi', 'edifact'], true)) {
            error_log("Warning: Recommended file extension is .edi or .edifact");
        }
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
        
        $segments = [];
        
        if ($config->includeUna) {
            $segments[] = SegmentGenerator::una($config);
        }
        
        $segments[] = SegmentGenerator::unb($config, $validatedData->messageRef);
        $segments[] = SegmentGenerator::unh($validatedData->messageRef, $config);
        $segments[] = SegmentGenerator::bgm($validatedData->orderNumber, "220", $config);
        $segments[] = SegmentGenerator::dtm("137", $validatedData->orderDate, $config->dateFormat, $config);
        
        if ($validatedData->deliveryDate !== null) {
            $segments[] = SegmentGenerator::dtm("2", $validatedData->deliveryDate, $config->dateFormat, $config);
        }
        
        if ($validatedData->currency !== null) {
            $segments[] = SegmentGenerator::cux($validatedData->currency, $config);
        }
        
        foreach ($validatedData->parties as $party) {
            $segments[] = SegmentGenerator::nad(
                $party->qualifier,
                $party->id,
                $party->name,
                $config
            );
            
            if ($party->address !== null) {
                $segments[] = SegmentGenerator::com($party->address, "AD", $config);
            }
            
            if ($party->contact !== null) {
                $segments[] = SegmentGenerator::com($party->contact, "TE", $config);
            }
        }
        
        $totalAmount = 0.0;
        $decimalRounding = $config->decimalRounding;
        
        foreach ($validatedData->items as $idx => $item) {
            $quantity = $item->quantity;
            $price = $item->price;
            $unit = $item->unit ?? "EA";
            
            $lineTotal = DecimalHelper::multiply($price, $quantity);
            $lineTotalRounded = (float)DecimalHelper::roundDecimal($lineTotal, $decimalRounding);
            
            $segments[] = SegmentGenerator::lin($idx + 1, $item->productCode, $config);
            
            if ($item->description !== '') {
                $segments[] = SegmentGenerator::imd($item->description, $config);
            }
            
            $segments[] = SegmentGenerator::qty($quantity, $unit, $config);
            $segments[] = SegmentGenerator::pri($price, $config, $unit);
            
            $totalAmount = DecimalHelper::add($totalAmount, $lineTotalRounded);
        }
        
        if ($validatedData->taxRate !== null) {
            $taxRate = $validatedData->taxRate;
            $taxAmount = DecimalHelper::divide(
                DecimalHelper::multiply($totalAmount, $taxRate),
                100.0
            );
            
            $taxAmountRounded = (float)DecimalHelper::roundDecimal($taxAmount, $decimalRounding);
            
            $segments[] = SegmentGenerator::tax($taxRate, "VAT", $config);
            $segments[] = SegmentGenerator::moa("124", $taxAmountRounded, $config);
            
            $totalAmount = DecimalHelper::add($totalAmount, $taxAmountRounded);
        }
        
        if ($validatedData->deliveryLocation !== null) {
            $segments[] = SegmentGenerator::loc("11", $validatedData->deliveryLocation, $config);
        }
        
        if ($validatedData->paymentTerms !== null) {
            $segments[] = SegmentGenerator::pai($validatedData->paymentTerms, $config);
        }
        
        if ($validatedData->incoterms !== null) {
            $segments[] = SegmentGenerator::tod($validatedData->incoterms, $config);
        }
        
        if ($validatedData->specialInstructions !== null) {
            $instructions = $validatedData->specialInstructions;
            $chunks = str_split($instructions, $config->maxFieldLength);
            
            foreach ($chunks as $i => $chunk) {
                $segments[] = SegmentGenerator::ftx($chunk, "AAI", $i + 1, $config);
            }
        }
        
        $segments[] = SegmentGenerator::moa("79", $totalAmount, $config);
        
        $unhIndex = null;
        foreach ($segments as $i => $segment) {
            if (strpos($segment, "UNH+") === 0) {
                $unhIndex = $i;
                break;
            }
        }
        
        if ($unhIndex === null) {
            throw new EdifactGeneratorException("UNH segment missing", "GEN_001");
        }
        
        $segmentCount = count($segments) - $unhIndex - 1;
        $segments[] = SegmentGenerator::unt($segmentCount, $validatedData->messageRef, $config);
        $segments[] = SegmentGenerator::unz(1, $validatedData->messageRef, $config);
        
        $edifactMessage = implode($config->lineEnding, $segments);
        
        error_log("Generated " . count($segments) . " segments");
        
        if ($outputFile !== null) {
            try {
                self::validateFilePath($outputFile);
                
                $result = file_put_contents($outputFile, $edifactMessage);
                if ($result === false) {
                    throw new RuntimeException("Failed to write to file");
                }
                
                error_log("EDIFACT message written to {$outputFile}");
            } catch (Exception $e) {
                error_log("Failed to write file: " . $e->getMessage());
                throw new EdifactGeneratorException("File write failed", "IO_001");
            }
        }
        
        return $edifactMessage;
    }
}

// Example usage
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
                "contact" => "+123456789"
            ],
            [
                "qualifier" => "SU",
                "id" => "3210987654321",
                "address" => "Industrial?Park",
                "contact" => "supplier@example.com"
            ],
        ],
        "items" => [
            [
                "product_code" => "ITEM001",
                "description" => "Widget A (Special)",
                "quantity" => 10.0,
                "price" => 12.50,
                "unit" => "EA"
            ],
        ],
        "delivery_date" => date('Ymd', strtotime('+7 days')),
        "currency" => "USD",
        "delivery_location" => "WAREHOUSE1",
        "payment_terms" => "NET30",
        "tax_rate" => 7.5,
        "special_instructions" => "Please deliver during business hours 9AM-5PM. Contact John Doe at extension 123 for delivery coordination.",
        "incoterms" => "FOB"
    ];
    
    $enhancedConfig = new EdifactConfig([
        "version" => "4",
        "release" => "22A",
        "controllingAgency" => "ISO",
        "lineEnding" => "\r\n",
        "senderId" => "BUYER123",
        "receiverId" => "SUPPLIER456",
        "maxFieldLength" => 70,
        "maxSegmentLength" => 2000
    ]);
    
    try {
        $message = EdifactGenerator::generateEdifactOrders(
            $sampleOrder,
            $enhancedConfig,
            "orders.edi"
        );
        
        echo "\nGenerated EDIFACT ORDERS:\n" . $message . "\n";
    } catch (EdifactGeneratorException $e) {
        echo "Generation failed: {$e->getErrorCode()} - {$e->getMessage()}\n";
        if (!empty($e->getDetails())) {
            echo "Error details: " . json_encode($e->getDetails(), JSON_PRETTY_PRINT) . "\n";
        }
    }
}
