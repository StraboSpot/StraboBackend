<?php
/**
 * File: mfooter_visual.php
 * Description: The visible site footer (social icons, Matomo tracker,
 *              sponsor logos, copyright). Split out of mfooter.php so
 *              full-viewport "app frame" pages (e.g. /strabosearch/)
 *              can load the script stack without the visual footer by
 *              including mfooter_close.php alone. Normal pages keep
 *              including mfooter.php, which composes both halves and
 *              is byte-identical in output to the pre-split file.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2025 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */
?>

			<!-- Footer -->
				<footer id="footer">
					<ul class="icons">
						<li><a href="https://www.youtube.com/channel/UC-bWoOvRvpedOswFERXQPiw" target="_blank" class="icon brands alt fa-youtube"><span class="label">YouTube</span></a></li>
						<li><a href="https://www.facebook.com/strabospotsystem/" target="_blank" class="icon brands alt fa-facebook-f"><span class="label">Facebook</span></a></li>
						<!--<li><a href="#" class="icon brands alt fa-linkedin-in"><span class="label">LinkedIn</span></a></li>
						<li><a href="#" class="icon brands alt fa-instagram"><span class="label">Instagram</span></a></li>-->
						<li><a href="https://github.com/StraboSpot" target="_blank" class="icon brands alt fa-github"><span class="label">GitHub</span></a></li>
						<li><a href="mailto:strabospot@gmail.com" class="icon solid alt fa-envelope"><span class="label">Email</span></a></li>
					</ul>


				<!-- Matomo -->
				<script>
				  var _paq = window._paq = window._paq || [];
				  /* tracker methods like "setCustomDimension" should be called before "trackPageView" */
				  _paq.push(['trackPageView']);
				  _paq.push(['enableLinkTracking']);
				  (function() {
					var u="//stats.strabospot.org/";
					_paq.push(['setTrackerUrl', u+'matomo.php']);
					_paq.push(['setSiteId', '1']);
					var d=document, g=d.createElement('script'), s=d.getElementsByTagName('script')[0];
					g.async=true; g.src=u+'matomo.js'; s.parentNode.insertBefore(g,s);
				  })();
				</script>
				<!-- End Matomo Code -->


					<div class="row gtr-uniform gtr-50">
						<div class="footerLink col-4 col-12-medium">
							<a href="/nsf_funding"><div><img class="footerImage" src="/includes/mimages/NSFLogo_grey.png"></div><div>Funded by the National Science Foundation</div></a>
						</div>
						<div class="footerLink col-4 col-12-medium">
							<a href="https://www.mapbox.com/" target="_blank"><div><img class="footerImage" src="/includes/mimages/mapbox_grey.png"></div><div>Maps Provided by Mapbox</div></a>
						</div>
						<div class="footerLink col-4 col-12-medium">
							<a href="https://www.earthcube.org/" target="_blank"><div><img class="footerImage" src="/includes/mimages/earthCubeLogo_grey.png"></div><div>EarthCube Partner</div></a>
						</div>
					</div>




					<ul class="copyright">
						<li>&copy; 2026 StraboSpot. All rights reserved.</li><!--<li>Design: <a href="http://html5up.net">HTML5 UP</a></li>-->
					</ul>
				</footer>
