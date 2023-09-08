<?php
/**
 * @copyright © TMS-Plugins. All rights reserved.
 * @licence   See LICENCE.md for license details.
 */

?>
<script>
  if (typeof hasAmeliaEvent === 'undefined') {
    var hasAmeliaEvent = true;
  }
  if (typeof hasEventApiCall === 'undefined' && '<?php echo esc_js($params['trigger']); ?>' === '') {
    var hasEventApiCall = true;
  }
  var hasEventShortcode = (typeof hasEventShortcode === 'undefined') ? false : true;
  var ameliaShortcodeData = (typeof ameliaShortcodeData === 'undefined') ? [] : ameliaShortcodeData;
  ameliaShortcodeData.push(
    {
      'hasApiCall': (typeof hasEventApiCall !== 'undefined') && hasEventApiCall,
      'trigger': '<?php echo esc_js($params['trigger']); ?>',
      'counter': '<?php echo esc_js($params['counter']); ?>',
      'employee': '<?php echo esc_js($params['employee']); ?>',
      'eventId': '<?php echo esc_js($params['event']); ?>',
      'eventRecurring': <?php echo $params['recurring'] ? 1 : 0; ?>,
      'eventTag': '<?php echo $params['tag']; ?>'
    }
  );
  var ameliaShortcodeDataTriggered = (typeof ameliaShortcodeDataTriggered === 'undefined') ? [] : ameliaShortcodeDataTriggered;
  if (ameliaShortcodeData[ameliaShortcodeData.length - 1].trigger !== '') {
    if (ameliaShortcodeDataTriggered.filter(a => a.counter === ameliaShortcodeData[ameliaShortcodeData.length - 1].counter).length === 0) {
      ameliaShortcodeDataTriggered.push(ameliaShortcodeData.pop());
    } else {
      ameliaShortcodeData.pop()
    }
  }
  if (typeof hasEventApiCall !== 'undefined' && hasEventApiCall) {
    hasEventApiCall = false;
  }
</script>

<div
  id="amelia-v2-booking-<?php echo esc_attr($params['counter']); ?>"
  class="amelia-v2-booking<?php echo $params['trigger'] !== '' ? ' amelia-skip-load amelia-skip-load-' . $params['counter'] : ''; ?>"
>
    <events-list-form-wrapper>
</div>
