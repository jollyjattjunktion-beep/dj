$ErrorActionPreference = 'Stop'
$PluginFolder = Split-Path -Parent $MyInvocation.MyCommand.Path
$PluginName = Split-Path -Leaf $PluginFolder
$ZipPath = Join-Path $env:TEMP 'jattbeat-song-catalog.zip'
if (Test-Path $ZipPath) { Remove-Item -LiteralPath $ZipPath -Force }
Compress-Archive -Path $PluginFolder -DestinationPath $ZipPath -Force
Write-Host "Created plugin ZIP: $ZipPath" -ForegroundColor Green
