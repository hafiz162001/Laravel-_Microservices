# test-flow.ps1 - Skrip Pengujian Alur Microservices End-to-End
# Pastikan container sudah berjalan via `docker compose up -d` atau jalan lokal via artisan.

$GATEWAY = "http://localhost"

Write-Host "1. Testing Gateway Health..." -ForegroundColor Cyan
try {
    $res = Invoke-RestMethod -Uri "$GATEWAY/healthz" -Method GET
    Write-Host "Gateway Status: $($res.status)" -ForegroundColor Green
} catch {
    Write-Host "Gateway belum aktif. Silakan jalankan 'docker compose up -d' terlebih dahulu." -ForegroundColor Red
    exit
}

Write-Host "`n2. Registering User di auth-service..." -ForegroundColor Cyan
$userEmail = "user$((Get-Random -Minimum 1000 -Maximum 9999))@example.com"
$registerBody = @{
    name = "John Doe"
    email = $userEmail
    password = "password123"
} | ConvertTo-Json

try {
    $regRes = Invoke-RestMethod -Uri "$GATEWAY/api/auth/register" -Method POST -Body $registerBody -ContentType "application/json"
    Write-Host "User Registered: $($regRes.user.email) (ID: $($regRes.user.id))" -ForegroundColor Green
} catch {
    Write-Host "Gagal register: $_" -ForegroundColor Red
}

Write-Host "`n3. Logging in di auth-service (Mendapatkan Stateless JWT)..." -ForegroundColor Cyan
$loginBody = @{
    email = $userEmail
    password = "password123"
} | ConvertTo-Json

$loginRes = Invoke-RestMethod -Uri "$GATEWAY/api/auth/login" -Method POST -Body $loginBody -ContentType "application/json"
$token = $loginRes.access_token
Write-Host "Login Sukses! JWT Token:" -ForegroundColor Green
Write-Host $token -ForegroundColor Yellow

$headers = @{
    Authorization = "Bearer $token"
}

Write-Host "`n4. Memanggil order-service (Melihat Order) Menggunakan Token JWT..." -ForegroundColor Cyan
$orderRes = Invoke-RestMethod -Uri "$GATEWAY/api/orders/" -Method GET -Headers $headers
Write-Host "Respons order-service berhasil memvalidasi JWT user:" -ForegroundColor Green
$orderRes | ConvertTo-Json -Depth 3

Write-Host "`n5. Membuat Order Baru di order-service..." -ForegroundColor Cyan
$newOrderBody = @{
    item = "Monitor 4K Dell Ultrasharp"
    amount = 8500000
} | ConvertTo-Json

$createOrderRes = Invoke-RestMethod -Uri "$GATEWAY/api/orders/" -Method POST -Headers $headers -Body $newOrderBody -ContentType "application/json"
Write-Host "Order Berhasil Dibuat!" -ForegroundColor Green
$createOrderRes | ConvertTo-Json -Depth 3

