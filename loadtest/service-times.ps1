# Local only: per-endpoint service time, one request at a time (no queueing), warm caches.
# Prints median and p95 of N sequential requests per endpoint. Run against
# serve-production-like.ps1, with SERVICE_AUTH_SECRET set (render-signed, like ISR):
#
#   .\loadtest\service-times.ps1 -BaseUrl http://127.0.0.1:8010 -ApiHost api.charlie.localhost
#
# The checkout quote is limited to 10 per minute per cart (api-conventions §8), so it is
# sampled 9 times.
param(
    [string] $BaseUrl = 'http://127.0.0.1:8010',
    [string] $ApiHost = 'api.charlie.localhost',
    [int] $Requests = 30
)

$ErrorActionPreference = 'Stop'
$api = "$BaseUrl/api/v1"
$headers = @{ Host = $ApiHost; Accept = 'application/json' }

if ($env:SERVICE_AUTH_SECRET) {
    $hmac = [System.Security.Cryptography.HMACSHA256]::new([Text.Encoding]::UTF8.GetBytes($env:SERVICE_AUTH_SECRET))
    $headers['X-Storefront-Render'] = -join ($hmac.ComputeHash([Text.Encoding]::UTF8.GetBytes('storefront-render:v1')) | ForEach-Object { $_.ToString('x2') })
}

function Measure-Endpoint([string] $Name, [scriptblock] $Call, [int] $Samples = $Requests) {
    & $Call | Out-Null   # warm
    $times = foreach ($i in 1..$Samples) {
        $sw = [Diagnostics.Stopwatch]::StartNew()
        & $Call | Out-Null
        $sw.Elapsed.TotalMilliseconds
    }
    $sorted = $times | Sort-Object
    [pscustomobject]@{
        Endpoint = $Name
        MedianMs = [math]::Round($sorted[[int]($sorted.Count / 2)])
        P95Ms    = [math]::Round($sorted[[int][math]::Ceiling($sorted.Count * 0.95) - 1])
    }
}

$products = (Invoke-RestMethod "$api/products?per_page=50" -Headers $headers).data
$product = $products | Where-Object in_stock | Select-Object -First 1
$variant = ((Invoke-RestMethod "$api/products/$($product.id)" -Headers $headers).data.variants | Where-Object in_stock | Select-Object -First 1).id

$cart = (Invoke-RestMethod "$api/carts" -Method Post -Headers $headers).data
$cartHeaders = $headers.Clone(); $cartHeaders['X-Cart-Token'] = $cart.token
Invoke-RestMethod "$api/carts/$($cart.id)/items" -Method Post -Headers $cartHeaders -ContentType 'application/json' -Body (@{ variant_id = $variant; quantity = 1 } | ConvertTo-Json) | Out-Null
$quote = @{
    cart_id = $cart.id; shipping_method = 'standard'
    shipping_address = @{ first_name = 'Load'; last_name = 'Test'; phone = '+92 300 0000000'; line1 = 'House 1'; city = 'Lahore'; province = 'Punjab'; country_code = 'PK' }
} | ConvertTo-Json

@(
    Measure-Endpoint 'GET storefront/config' { Invoke-WebRequest -UseBasicParsing "$api/storefront/config" -Headers $headers }
    Measure-Endpoint 'GET categories' { Invoke-WebRequest -UseBasicParsing "$api/categories" -Headers $headers }
    Measure-Endpoint 'GET products (24, category)' { Invoke-WebRequest -UseBasicParsing "$api/products?per_page=24&filter[category]=bedding" -Headers $headers }
    Measure-Endpoint 'GET products/by-slug' { Invoke-WebRequest -UseBasicParsing "$api/products/by-slug/$($product.slug)" -Headers $headers }
    Measure-Endpoint 'GET search (typo)' { Invoke-WebRequest -UseBasicParsing "$api/search?q=velvt%20cushon&per_page=24" -Headers $headers }
    Measure-Endpoint 'GET search/suggest' { Invoke-WebRequest -UseBasicParsing "$api/search/suggest?q=cush" -Headers $headers }
    Measure-Endpoint 'GET carts/{id}' { Invoke-WebRequest -UseBasicParsing "$api/carts/$($cart.id)" -Headers $cartHeaders }
    Measure-Endpoint 'POST checkout/quote' { Invoke-WebRequest -UseBasicParsing "$api/checkout/quote" -Method Post -Headers $cartHeaders -ContentType 'application/json' -Body $quote } -Samples 8
) | Format-Table -AutoSize
