<?php
/**
 * File: exportproject2.php
 * Description: Instructions for exporting a StraboField project to PC or Mac
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

include 'includes/mheader.php';
?>

<style type='text/css'>
.howtostep {
	padding-top: 0px;
	font-size: 1.2em;
}
.olsteps {
	padding-left: 20px;
}
</style>

			<!-- Main -->
				<div id="main" class="wrapper style1">
					<div class="container">

						<header class="major">
							<h2>Exporting StraboField Project to PC or Mac</h2>
						</header>

<div>
From time to time, it might be necessary or desirable to export a StraboField project from your
mobile device to a PC or Mac. You might want to keep a permanent offline copy of your projects' data,
or you might want to share a project with another user or a member of the Strabo development team.
Whatever the reason, the following steps should allow you to export a StraboField project to your PC or Mac.
</div>

<div style="text-align:center;padding-top:25px;">
	<h3>First, Export Project to Mobile Device</h3>
</div>

<div class="howtostep">
	<ol class="olsteps">
		<li>
			With the desired project and datasets active within StraboField, click on the upper-left Home menu and click "MANAGE PROJECT -> Backup".
		</li>
		<li>
			Next, click "Save".
		</li>
		<li>
			Confirm or change the folder name you would like to export to. It is likely safe to keep the default value.
		</li>
		<li>
			Click "Save" to export the folder to your device.
		</li>
	</ol>
</div>

<div style="padding-top:15px;">
Now that the project has been exported to your mobile device, you will need to transfer this project
to your PC or Mac.
</div>


<!--
<div style="text-align:center;padding-top:25px;">
	<h3>Android</h3>
</div>

<div class="howtostep">
	<ol class="olsteps">
		<li>
			If using a Mac, download and install <a href="http://www.android.com/filetransfer/" target="_blank">Android File Transfer</a> on your computer.
			Open Android File Transfer. The next time that you connect your mobile device, it will open automatically.
		</li>
		<li>
			Unlock your mobile device.
		</li>
		<li>
			With a USB cable, connect your mobile device to your computer.
		</li>
		<li>
			On your mobile device, tap the "Charging this device via USB" notification.
		</li>
		<li>
			Under "Use USB for," select File Transfer.
		</li>
		<li>
			An Android File Transfer window will open on your computer. Use it to drag files.
		</li>
		<li>
			The folder you exported from StraboMobile will be located in the root of your device in
			the StraboSpotProjects folder. You should transfer the folder created earlier to your computer.
		</li>
		<li>
			When you’re done, unplug the USB cable.
		</li>
		<li>
			Once the folder is on your local computer, it will be necessary to ZIP the folder in order
			to share the data with others.
		</li>
	</ol>
</div>
-->

<div style="text-align:center;padding-top:25px;">
	<h3>Transfer to Mac</h3>
</div>

<div class="howtostep">
	<ol class="olsteps">
		<li>
			Unlock your mobile device.
		</li>
		<li>
			With a USB cable, connect your mobile device to your Mac.
		</li>
		<li>
			Open finder on your Mac. Click on your mobile device located under Locations in the left-side bar.
		</li>
		<li>
			You should now see a list of applications on your iOS mobile device. Click on the arrow to the left
			of StraboField.
		</li>
		<li>
			Drag and drop the "ProjectBackups" folder to any location on your Mac. (for example, the Desktop)
		</li>
		<li>
			Your exported StraboMobile2 project data will be located in the ProjectBackups folder.
		</li>
		<li>
			When you’re done, unplug the USB cable.
		</li>
		<li>
			Once the folder is on your local computer, it will be necessary to ZIP the folder in order
			to share the data with others.
		</li>
	</ol>
</div>

<div style="text-align:center;padding-top:25px;">
	<h3>Transfer to Windows using iTunes</h3>
</div>

<div class="howtostep">
	<ol class="olsteps">
		<li>
			Make sure that you have <a href="https://www.apple.com/itunes/" target="_blank">installed iTunes</a>.
		</li>
		<li>
			Unlock your mobile device.
		</li>
		<li>
			With a USB cable, connect your mobile device to your PC.
		</li>
		<li>
			Open iTunes, click the “Files” tab.
		</li>
		<li>
			Expand the StraboField application and click the ProjectBackups folder.
		</li>
		<li>
			Click “Sync” to transfer the ProjectBackups folder to your PC.
		</li>
		<li>
			Your exported StraboField project data will be located in the ProjectBackups folder.
		</li>
		<li>
			When you’re done, unplug the USB cable.
		</li>
		<li>
			Once the folder is on your local computer, it will be necessary to ZIP the folder in order
			to share the data with others.
		</li>
	</ol>
</div>

					<div class="bottomSpacer"></div>

					</div>
				</div>

<?php
include 'includes/mfooter.php';
?>
