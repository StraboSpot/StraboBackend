<?php
/**
 * File: mfooter_close.php
 * Description: Closes #page-wrapper (opened by mheader.php) and loads
 *              the site script stack, including dropotron which powers
 *              the #header dropdown menus. Split out of mfooter.php so
 *              full-viewport "app frame" pages (e.g. /strabosearch/)
 *              can include this alone and skip the visual footer while
 *              keeping the header nav functional. Normal pages keep
 *              including mfooter.php, which composes both halves.
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2025 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */
?>

		</div>

		<!-- Scripts -->
		<script src="/massets/js/jquery.min.js"></script>
		<script src="/massets/js/jquery.scrolly.min.js"></script>
		<script src="/massets/js/jquery.dropotron.min.js"></script>
		<script src="/massets/js/jquery.scrollex.min.js"></script>
		<script src="/massets/js/browser.min.js"></script>
		<script src="/massets/js/breakpoints.min.js"></script>
		<script src="/massets/js/util.js"></script>
		<script src="/massets/js/main.js"></script>
		<script src="/geotiff/js/vendor/jquery.ui.widget.js"></script>
		<script src="/geotiff/js/jquery.iframe-transport.js"></script>
		<script src="/geotiff/js/jquery.fileupload.js"></script>
		<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>
		<script src="//cdn.rawgit.com/noelboss/featherlight/1.7.13/release/featherlight.min.js" type="text/javascript" charset="utf-8"></script>
		<!-- Return to Top Button -->
		<a href="#" id="return-to-top" title="Return to top" aria-label="Return to top">&#9650;</a>
		<script>
		(function() {
			var btn = document.getElementById('return-to-top');
			window.addEventListener('scroll', function() {
				btn.classList.toggle('visible', window.scrollY > 300);
			});
			btn.addEventListener('click', function(e) {
				e.preventDefault();
				window.scrollTo({ top: 0, behavior: 'smooth' });
			});
		})();
		</script>
	</body>
</html>