<?

function dumpVar($var){
	echo "<pre>";
	print_r($var);
	echo "</pre>";
}


$json = file_get_contents("https://raw.githubusercontent.com/jasonash/StraboMicro2/refs/heads/develop/dev-releases.json");
$data = json_decode($json);

include("includes/mheader.php");
include("Parsedown.php");
$pd = new Parsedown();

$notes = $pd->text($data->notes);

?>

<style>
* {
  /*outline: 1px solid red;*/
}
</style>




			<!-- Main -->
				<div id="main" class="wrapper style1">
					<div class="container">

						<header class="major">
							<h2>StraboMicro <?php echo $data->name?></h2>
							<!--<p>Ipsum dolor feugiat aliquam tempus sed magna lorem consequat accumsan</p>-->
						</header>

						<!-- Content -->
							<section id="content">


								<div class="row">
									<div class="col-6 col-12-xsmall" style="outline: 1px solid grey;margin-left: auto; margin-right: auto; border-radius:10px; background-color:#272833; padding: 15px; margin-top:30px;">

										<div style="text-align:center;font-weight:bold;font-size:2em;padding-bottom:5px;">WARNING!!!</div>
										
										Please note that this is a DEVELOPMENT version of StraboMicro and is not meant for production use! Please be careful when using this application
										and do not rely on it to preserve your data.

									</div>
								</div>

								<div style="padding-top:40px;">
									<h3>Release Date: <?php echo $data->date?></h3>
									<h3>Downloads:</h3>
									<div style="padding-left:20px;">
										<ul>
							<?php
								foreach($data->assets as $a){
							?>
											<li><?php echo $a->platform; ?>: <a href="<?php echo $a->url; ?>" target="_blank"><?php echo $a->name; ?></a></li>
							<?php
							}
							?>
										</ul>
									</div>
									
									<h3>Notes:</h3>
									
									<div>
										<?php echo $notes; ?>
									</div>
									
								</div>



							</section>
					</div>
				</div>



<div class="bottomSpacer"></div>



<?
include("includes/mfooter.php");


/*
stdClass Object
(
    [assets] => Array
        (
            [0] => stdClass Object
                (
                    [name] => StraboMicro2.Setup.2.0.0-beta.9-dev.52.exe
                    [platform] => Windows
                    [size] => 169924829
                    [url] => https://github.com/jasonash/StraboMicro2/releases/download/dev-latest/StraboMicro2.Setup.2.0.0-beta.9-dev.52.exe
                )

            [1] => stdClass Object
                (
                    [name] => StraboMicro2-2.0.0-beta.9-dev.52-arm64.dmg
                    [platform] => macOS (Apple Silicon)
                    [size] => 187680288
                    [url] => https://github.com/jasonash/StraboMicro2/releases/download/dev-latest/StraboMicro2-2.0.0-beta.9-dev.52-arm64.dmg
                )

            [2] => stdClass Object
                (
                    [name] => StraboMicro2-2.0.0-beta.9-dev.52.dmg
                    [platform] => macOS (Intel)
                    [size] => 195325549
                    [url] => https://github.com/jasonash/StraboMicro2/releases/download/dev-latest/StraboMicro2-2.0.0-beta.9-dev.52.dmg
                )

            [3] => stdClass Object
                (
                    [name] => StraboMicro2-2.0.0-beta.9-dev.52.AppImage
                    [platform] => Linux (AppImage)
                    [size] => 389110811
                    [url] => https://github.com/jasonash/StraboMicro2/releases/download/dev-latest/StraboMicro2-2.0.0-beta.9-dev.52.AppImage
                )

            [4] => stdClass Object
                (
                    [name] => strabomicro2_2.0.0-beta.9-dev.52_amd64.deb
                    [platform] => Linux (Debian)
                    [size] => 184889360
                    [url] => https://github.com/jasonash/StraboMicro2/releases/download/dev-latest/strabomicro2_2.0.0-beta.9-dev.52_amd64.deb
                )

        )

    [branch] => develop
    [build_number] => 52
    [date] => 2026-01-13T19:12:25Z
    [name] => Development Build (v2.0.0-beta.9-dev.52)
    [notes] => ## Development Build

**Version:** 2.0.0-beta.9-dev.52
**Branch:** develop
**Build:** #52
**Date:** 2026-01-04T20:18:21Z

⚠️ **This is a development build for testing purposes.**

### Downloads

| Platform | File |
|----------|------|
| macOS (Apple Silicon) | `StraboMicro2-*-arm64.dmg` |
| macOS (Intel) | `StraboMicro2-*.dmg` (no arch suffix) |
| Windows | `StraboMicro2 Setup *.exe` |
| Linux (AppImage) | `StraboMicro2-*.AppImage` |
| Linux (Debian) | `strabomicro2_*_amd64.deb` |

### Installation

**macOS**: Download the DMG, open it, and drag StraboMicro2 to your Applications folder. The app is signed and notarized.

**Windows**: Download and run the installer. You may see a SmartScreen warning since the Windows build is not code-signed. Click "More info" then "Run anyway".

**Linux (AppImage)**: Download the AppImage, make it executable (`chmod +x`), and run with: `./StraboMicro2-*.AppImage --no-sandbox`

**Linux (Debian/Ubuntu)**: Install the .deb package: `sudo dpkg -i strabomicro2_*.deb`

    [version] => dev-latest
)
*/

?>