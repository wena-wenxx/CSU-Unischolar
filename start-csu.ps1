# CSU UniScholar: start everything on this computer (Windows PowerShell).
#
#   Right-click > Run with PowerShell, or in a terminal:   powershell -ExecutionPolicy Bypass -File .\start-csu.ps1
#
# It opens four windows:
#   1. Backend     php artisan serve                      http://127.0.0.1:8000
#   2. AI worker   php artisan queue:work (AI checks)     runs AI document checks in the background, so the
#                                                          website does not wait for them; restarts by itself
#                                                          if it stops and shows why
#   3. AI service  uvicorn main:app --port 8001            http://127.0.0.1:8001/health
#   4. Website     npm run dev                             http://localhost:5173
# Close a window (or press Ctrl+C in it) to stop that part. Start MySQL in XAMPP first.

$root = Split-Path -Parent $MyInvocation.MyCommand.Path

function Test-Port($port) {
    try {
        $client = New-Object System.Net.Sockets.TcpClient
        $wait = $client.BeginConnect("127.0.0.1", $port, $null, $null)
        $ok = $wait.AsyncWaitHandle.WaitOne(800) -and $client.Connected
        $client.Close()
        return $ok
    } catch { return $false }
}

if (-not (Test-Port 3306)) {
    Write-Host ""
    Write-Host "MySQL is NOT running on port 3306." -ForegroundColor Red
    Write-Host "Open the XAMPP Control Panel and press Start next to MySQL, then run this script again." -ForegroundColor Yellow
    Write-Host "If MySQL stops by itself, press Logs next to MySQL in XAMPP and send the last lines to the team."
    Read-Host "Press Enter to close"
    exit 1
}

$python = Join-Path $root "ai-service\.venv\Scripts\python.exe"
if (-not (Test-Path $python)) { $python = "python" }

Start-Process powershell -WorkingDirectory "$root\backend" -ArgumentList "-NoExit", "-Command",
    "`$host.UI.RawUI.WindowTitle = '1 Backend'; php artisan serve"
# The AI worker restarts by itself if it stops, and says why (exit code), so AI checks never silently stop
# running in the background. Close the window to stop it.
$worker = @'
$host.UI.RawUI.WindowTitle = '2 AI worker'
while ($true) {
    Write-Host ("[{0:HH:mm:ss}] AI worker running. It waits here for AI checks (nothing is shown until one comes)." -f (Get-Date)) -ForegroundColor Green
    php artisan queue:work database --queue=ai --timeout=300 --tries=1 --sleep=1 --memory=512 -v
    $code = $LASTEXITCODE
    $why = switch ($code) {
        0  { 'it was asked to stop, or it lost the connection to MySQL (is MySQL still running in XAMPP?)' }
        12 { 'it used more than 512 MB of memory' }
        default { 'an error (see the lines above and backend\storage\logs\laravel.log)' }
    }
    Write-Host ("[{0:HH:mm:ss}] AI worker stopped (exit code {1}): {2}. Starting again in 5 seconds..." -f (Get-Date), $code, $why) -ForegroundColor Yellow
    Start-Sleep -Seconds 5
}
'@
# Sent encoded so the quotes inside the script reach the new window unchanged.
$encoded = [Convert]::ToBase64String([Text.Encoding]::Unicode.GetBytes($worker))
Start-Process powershell -WorkingDirectory "$root\backend" -ArgumentList "-NoExit", "-EncodedCommand", $encoded
Start-Process powershell -WorkingDirectory "$root\ai-service" -ArgumentList "-NoExit", "-Command",
    "`$host.UI.RawUI.WindowTitle = '3 AI service'; & '$python' -m uvicorn main:app --port 8001"
Start-Process powershell -WorkingDirectory "$root\frontend" -ArgumentList "-NoExit", "-Command",
    "`$host.UI.RawUI.WindowTitle = '4 Website'; npm run dev"

Write-Host "Started. Open http://localhost:5173 (the AI service needs about a minute to load PaddleOCR)." -ForegroundColor Green
