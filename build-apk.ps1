$ErrorActionPreference = "Stop"

$ProjectRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
Set-Location $ProjectRoot

$JavaHome = "C:\Program Files\Java\jdk-24"
$env:JAVA_HOME = $JavaHome
$Javac = Join-Path $JavaHome "bin\javac.exe"
$Keytool = Join-Path $JavaHome "bin\keytool.exe"

$AndroidSdk = "C:\Android"
$BuildTools = Join-Path $AndroidSdk "build-tools\35.0.0"
$PlatformJar = Join-Path $AndroidSdk "platforms\android-35\android.jar"

$Aapt2 = Join-Path $BuildTools "aapt2.exe"
$D8 = Join-Path $BuildTools "d8.bat"
$ZipAlign = Join-Path $BuildTools "zipalign.exe"
$ApkSigner = Join-Path $BuildTools "apksigner.bat"

Write-Host "==> Step 1: Building Vite production bundle..." -ForegroundColor Cyan
& npm.cmd run build
if ($LASTEXITCODE -ne 0) { throw "Vite build failed." }

$AndroidDir = Join-Path $ProjectRoot "android-app"
$BuildDir = Join-Path $AndroidDir "build"
$AssetsWww = Join-Path $AndroidDir "assets\www"
$ResDrawable = Join-Path $AndroidDir "res\drawable"

if (Test-Path $BuildDir) { Remove-Item -Recurse -Force $BuildDir }
if (Test-Path $AssetsWww) { Remove-Item -Recurse -Force $AssetsWww }
New-Item -ItemType Directory -Force -Path $BuildDir | Out-Null
New-Item -ItemType Directory -Force -Path (Join-Path $BuildDir "gen") | Out-Null
New-Item -ItemType Directory -Force -Path (Join-Path $BuildDir "classes") | Out-Null
New-Item -ItemType Directory -Force -Path (Join-Path $BuildDir "dex") | Out-Null
New-Item -ItemType Directory -Force -Path $AssetsWww | Out-Null
New-Item -ItemType Directory -Force -Path $ResDrawable | Out-Null

Write-Host "==> Step 2: Preparing Android assets & icons..." -ForegroundColor Cyan
Copy-Item -Force (Join-Path $ProjectRoot "public\images\jeepney-tracker-logo.png") (Join-Path $ResDrawable "ic_launcher.png")
Copy-Item -Recurse -Force (Join-Path $ProjectRoot "public\build") (Join-Path $AssetsWww "build")
Copy-Item -Recurse -Force (Join-Path $ProjectRoot "public\images") (Join-Path $AssetsWww "images")

$ManifestJson = Get-Content (Join-Path $ProjectRoot "public\build\manifest.json") -Raw | ConvertFrom-Json
$AppEntry = $ManifestJson."resources/js/app.jsx"
$TransportEntry = $ManifestJson."resources/js/Pages/Transport.jsx"

$CssLinks = @()
if ($AppEntry.css) { $CssLinks += $AppEntry.css }
if ($TransportEntry.css) { $CssLinks += $TransportEntry.css }
foreach ($prop in $ManifestJson.PSObject.Properties) {
    if ($prop.Name -like "_DatabaseTransport*.css" -or $prop.Name -like "_TransportMap*.css") {
        $CssLinks += $prop.Value.file
    }
}
$CssLinks = $CssLinks | Select-Object -Unique

$CssTags = ($CssLinks | ForEach-Object { "    <link rel=`"stylesheet`" href=`"/build/$_`">" }) -join "`r`n"
$AppJsFile = $AppEntry.file

$IndexHtml = @"
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
    <title>JeepTrack Mobile</title>
$CssTags
    <script>
        window.__JEEPTRACK_MOBILE__ = true;
        window.__JEEPTRACK_DEFAULT_SERVER__ = "http://192.168.100.13:8000";
    </script>
</head>
<body class="font-sans antialiased">
    <div id="app" data-page='{"component":"Transport","props":{"databaseMode":true,"transportData":null,"errors":{}},"url":"/login","version":"1.0.0"}'></div>
    <script type="module" src="/build/$AppJsFile"></script>
</body>
</html>
"@

Set-Content -Path (Join-Path $AssetsWww "index.html") -Value $IndexHtml -Encoding UTF8

Write-Host "==> Step 3: Compiling Android resources with aapt2..." -ForegroundColor Cyan
$ResZip = Join-Path $BuildDir "res.zip"
$UnsignedApk = Join-Path $BuildDir "unsigned.apk"
& $Aapt2 compile --dir (Join-Path $AndroidDir "res") -o $ResZip
if ($LASTEXITCODE -ne 0) { throw "aapt2 compile failed." }

& $Aapt2 link `
    -o $UnsignedApk `
    -I $PlatformJar `
    --manifest (Join-Path $AndroidDir "AndroidManifest.xml") `
    --java (Join-Path $BuildDir "gen") `
    -A (Join-Path $AndroidDir "assets") `
    $ResZip `
    --auto-add-overlay
if ($LASTEXITCODE -ne 0) { throw "aapt2 link failed." }

Write-Host "==> Step 4: Compiling Java sources with javac..." -ForegroundColor Cyan
$JavaFiles = @(
    (Join-Path $AndroidDir "src\com\puj\jeeptrack\MainActivity.java"),
    (Join-Path $BuildDir "gen\com\puj\jeeptrack\R.java")
)
& $Javac --release 11 -cp $PlatformJar -d (Join-Path $BuildDir "classes") @JavaFiles
if ($LASTEXITCODE -ne 0) { throw "javac compilation failed." }

Write-Host "==> Step 5: Converting bytecode to DEX with d8..." -ForegroundColor Cyan
$ClassFiles = Get-ChildItem -Path (Join-Path $BuildDir "classes") -Filter "*.class" -Recurse | Select-Object -ExpandProperty FullName
& $D8 --lib $PlatformJar --min-api 24 --output (Join-Path $BuildDir "dex") @ClassFiles
if ($LASTEXITCODE -ne 0) { throw "d8 conversion failed." }

Write-Host "==> Step 6: Packaging classes.dex into APK..." -ForegroundColor Cyan
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem
$Zip = [System.IO.Compression.ZipFile]::Open($UnsignedApk, [System.IO.Compression.ZipArchiveMode]::Update)
try {
    $ExistingDex = $Zip.GetEntry("classes.dex")
    if ($ExistingDex) { $ExistingDex.Delete() }
    [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
        $Zip,
        (Join-Path $BuildDir "dex\classes.dex"),
        "classes.dex",
        [System.IO.Compression.CompressionLevel]::Optimal
    ) | Out-Null
} finally {
    $Zip.Dispose()
}

Write-Host "==> Step 7: Aligning APK with zipalign..." -ForegroundColor Cyan
$AlignedApk = Join-Path $BuildDir "aligned.apk"
& $ZipAlign -f -p 4 $UnsignedApk $AlignedApk
if ($LASTEXITCODE -ne 0) { throw "zipalign failed." }

Write-Host "==> Step 8: Signing APK with apksigner..." -ForegroundColor Cyan
$Keystore = Join-Path $AndroidDir "jeeptrack-release.jks"
if (-not (Test-Path $Keystore)) {
    & $Keytool -genkeypair -v `
        -keystore $Keystore `
        -alias jeeptrack `
        -keyalg RSA `
        -keysize 2048 `
        -validity 10000 `
        -storepass jeeptrack123 `
        -keypass jeeptrack123 `
        -dname "CN=JeepTrack PUJ Analytics, OU=Transport, O=PUJ, L=Batac, ST=Ilocos Norte, C=PH"
    if ($LASTEXITCODE -ne 0) { throw "keytool failed." }
}

$PublicDownloads = Join-Path $ProjectRoot "public\downloads"
New-Item -ItemType Directory -Force -Path $PublicDownloads | Out-Null
$FinalApk = Join-Path $PublicDownloads "JeepTrack.apk"
$RootApk = Join-Path $ProjectRoot "JeepTrack.apk"

& $ApkSigner sign `
    --ks $Keystore `
    --ks-key-alias jeeptrack `
    --ks-pass pass:jeeptrack123 `
    --key-pass pass:jeeptrack123 `
    --out $FinalApk `
    $AlignedApk
if ($LASTEXITCODE -ne 0) { throw "apksigner sign failed." }

& $ApkSigner verify --verbose $FinalApk
if ($LASTEXITCODE -ne 0) { throw "apksigner verify failed." }

Copy-Item -Force $FinalApk $RootApk
$ApkInfo = Get-Item $FinalApk
Write-Host "==> SUCCESS! Signed Android APK generated:" -ForegroundColor Green
Write-Host "    1) $($ApkInfo.FullName) ($([Math]::Round($ApkInfo.Length / 1MB, 2)) MB)" -ForegroundColor Green
Write-Host "    2) $RootApk" -ForegroundColor Green
