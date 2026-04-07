<?php
/**
 * File: events.php
 * Description: Events - Upcoming workshops, courses, field trips, and conferences
 *
 * @package    StraboSpot Web Site
 * @author     Jason Ash <jasonash@ku.edu>
 * @copyright  2026 StraboSpot
 * @license    https://opensource.org/licenses/MIT MIT License
 * @link       https://strabospot.org
 */

include("includes/mheader.php");

$tag_labels = array(
	'inperson' => 'In Person',
	'virtual' => 'Virtual',
	'workshop' => 'Workshop',
	'shortcourse' => 'Short Course',
	'fieldtrip' => 'Field Trip',
	'conference' => 'Conference',
	'poster' => 'Poster',
	'talk' => 'Talk'
);

$events = array();
$jsonFile = __DIR__ . '/data/events.json';
if(file_exists($jsonFile)){
	$events_data = json_decode(file_get_contents($jsonFile), true);
	$events = $events_data['events'] ?? array();
	usort($events, function($a, $b){
		return ($a['sort_order'] ?? 0) - ($b['sort_order'] ?? 0);
	});
}
?>

<style>
	.event-tag {
		display: inline-block;
		color: #ffffff;
		font-size: 0.85em;
		font-weight: 700;
		padding: 0.2em 0.6em;
		margin-right: 0.4em;
		margin-bottom: 0.4em;
		border-radius: 2px;
	}
	.event-tag-inperson { background-color: #b45f06; }
	.event-tag-virtual { background-color: #bf9000; }
	.event-tag-workshop { background-color: #85200c; }
	.event-tag-shortcourse { background-color: #0b5394; }
	.event-tag-fieldtrip { background-color: #351c75; }
	.event-tag-conference { background-color: #741b47; }
	.event-tag-poster { background-color: #38761d; }
	.event-tag-talk { background-color: #134f5c; }

	.event-row {
		display: flex;
		align-items: stretch;
		border: 1px solid rgba(255, 255, 255, 0.1);
		margin-bottom: 1.5em;
		border-radius: 4px;
		overflow: hidden;
	}

	.event-date {
		flex: 0 0 130px;
		display: flex;
		flex-direction: column;
		justify-content: center;
		align-items: center;
		text-align: center;
		padding: 1.5em 1em;
		border-right: 1px solid rgba(255, 255, 255, 0.1);
	}

	.event-date-days {
		color: #e44c65;
		font-size: 1.6em;
		font-weight: 700;
		line-height: 1.2;
	}

	.event-date-month,
	.event-date-year {
		color: #e44c65;
		font-size: 1.1em;
		font-weight: 700;
	}

	.event-details {
		flex: 1;
		padding: 1.5em;
	}

	.event-title {
		color: #ffffff;
		font-size: 1.4em;
		font-weight: 700;
		margin: 0 0 0.5em 0;
	}

	.event-tags {
		margin-bottom: 0.75em;
	}

	.event-description {
		color: rgba(255, 255, 255, 0.85);
		line-height: 1.6;
		margin-bottom: 0.5em;
	}

	.event-register {
		color: #e44c65;
		font-style: italic;
	}

	.event-image {
		flex: 0 0 160px;
		display: flex;
		align-items: center;
		justify-content: center;
		padding: 1em;
	}

	.event-image img {
		max-width: 100%;
		max-height: 150px;
		height: auto;
		border-radius: 4px;
	}

	.event-filter-btn {
		cursor: pointer;
		transition: opacity 0.2s;
	}

	@media screen and (max-width: 736px) {
		.event-row {
			flex-direction: column;
		}

		.event-date {
			flex: none;
			flex-direction: row;
			gap: 0.5em;
			padding: 1em;
			border-right: none;
			border-bottom: 1px solid rgba(255, 255, 255, 0.1);
		}

		.event-image {
			flex: none;
			padding: 1em;
		}
	}
</style>

<!-- Main -->
<div id="main" class="wrapper style1">
	<div class="container">

		<header class="major">
			<h2>Events</h2>
		</header>

		<!-- Intro -->
		<section class="micro-section">
			<h2 class="exp-section-title" style="font-size: 1.6em;">Explore. Learn. Connect.</h2>
			<p style="color: rgba(255, 255, 255, 0.85); font-size: 1.05em; line-height: 1.7; margin-bottom: 2em;">
				Discover upcoming workshops, online opportunities, short courses, field trips, and conferences
				that help you grow, collaborate, and advance your work with StraboSpot.
			</p>
		</section>

		<!-- Filters -->
		<section class="micro-section">
			<p style="color: #ffffff; font-weight: 700; font-size: 1.1em; margin-bottom: 0.5em;">Filters:</p>
			<div style="margin-bottom: 2em;">
				<?php foreach($tag_labels as $key => $label): ?>
				<span class="event-tag event-tag-<?php echo $key; ?> event-filter-btn" data-tag="<?php echo $key; ?>"><?php echo htmlspecialchars($label); ?></span>
				<?php endforeach; ?>
			</div>
		</section>

		<?php if(empty($events)): ?>
		<p style="color: rgba(255, 255, 255, 0.7); font-size: 1.1em;">No upcoming events at this time. Check back soon!</p>
		<?php else: ?>
		<?php foreach($events as $event): ?>
		<div class="event-row" data-tags="<?php echo htmlspecialchars(implode(' ', $event['tags'] ?? array())); ?>">
			<div class="event-date">
				<span class="event-date-days"><?php echo htmlspecialchars(!empty($event['end_day']) && $event['end_day'] != $event['start_day'] ? $event['start_day'] . '-' . $event['end_day'] : $event['start_day']); ?></span>
				<span class="event-date-month"><?php echo htmlspecialchars($event['month']); ?></span>
				<span class="event-date-year"><?php echo htmlspecialchars($event['year']); ?></span>
			</div>
			<div class="event-details">
				<h3 class="event-title"><?php echo htmlspecialchars($event['title']); ?></h3>
				<div class="event-tags">
					<?php foreach(($event['tags'] ?? array()) as $tag): ?>
					<span class="event-tag event-tag-<?php echo htmlspecialchars($tag); ?>"><?php echo htmlspecialchars($tag_labels[$tag] ?? $tag); ?></span>
					<?php endforeach; ?>
				</div>
				<p class="event-description"><?php echo htmlspecialchars($event['description']); ?></p>
				<?php if(!empty($event['register_url'])): ?>
				<p class="event-register"><a href="<?php echo htmlspecialchars($event['register_url']); ?>" target="_blank" style="color: #e44c65;"><?php echo htmlspecialchars($event['register_text'] ?: 'Register Here:'); ?></a></p>
				<?php elseif(!empty($event['register_text'])): ?>
				<p class="event-register"><?php echo htmlspecialchars($event['register_text']); ?></p>
				<?php endif; ?>
			</div>
			<?php if(!empty($event['image'])): ?>
			<div class="event-image">
				<img src="/includes/mimages/events/<?php echo htmlspecialchars($event['image']); ?>" alt="<?php echo htmlspecialchars($event['title']); ?>">
			</div>
			<?php endif; ?>
		</div>
		<?php endforeach; ?>
		<?php endif; ?>

		<div class="bottomSpacer"></div>

	</div>
</div>

<script>
$(function(){
	// Track which filters are active (all start active)
	var allTags = <?php echo json_encode(array_keys($tag_labels)); ?>;
	var activeTags = allTags.slice();

	$('.event-filter-btn').click(function(){
		var tag = $(this).data('tag');
		var idx = activeTags.indexOf(tag);

		if(idx !== -1){
			// Deselect this tag
			activeTags.splice(idx, 1);
			$(this).css('opacity', '0.35');
		} else {
			// Re-select this tag
			activeTags.push(tag);
			$(this).css('opacity', '1');
		}

		// If all tags are active again, show everything
		if(activeTags.length === allTags.length){
			$('.event-row').show();
			return;
		}

		// Show events that have at least one active tag
		$('.event-row').each(function(){
			var rowTags = $(this).data('tags').toString().split(' ');
			var match = false;
			for(var i = 0; i < rowTags.length; i++){
				if(activeTags.indexOf(rowTags[i]) !== -1){
					match = true;
					break;
				}
			}
			$(this).toggle(match);
		});
	});
});
</script>

<?php
include("includes/mfooter.php");
?>
