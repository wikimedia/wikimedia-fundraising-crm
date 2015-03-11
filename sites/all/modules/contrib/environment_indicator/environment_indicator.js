/**
 * @file
 * Environment info JavaScript.
 *
 * @author Tom Kirkpatrick (mrfelton), www.systemseed.com
 */

(function ($) {

Drupal.environmentIndicator = Drupal.environmentIndicator || {};

/**
 * Core behavior for Environment Indicator.
 *
 * Test whether there is an environment indicator in the output and execute all
 * registered behaviors.
 */
Drupal.behaviors.environmentIndicator = {
  attach: function(context, settings) {
 
    // Initialize settings.
   settings.environment_indicator = $.extend({
      text: ' ',
      color: '#d00c0c',
      suppress: false,
      margin: false,
      position: 'left'
    }, settings.environment_indicator || {});
    
    // Check whether environment indicator strip menu should be suppressed.
    if (settings.environment_indicator.suppress) {
      return;
    };
    
    if ($('body:not(.environment-indicator-processed|.overlay)', context).length) {
      settings.environment_indicator.cssClass = 'environment-indicator-' + settings.environment_indicator.position;
      
      // If we don't have an environment indicator, inject it into the document.
      var $environmentIndicator = $('#environment-indicator', context);
      if (!$environmentIndicator.length) {
        $('body', context).prepend('<div id="environment-indicator">' + settings.environment_indicator.text + '</div>');
        $('body', context).addClass(settings.environment_indicator.cssClass);
        
        // Set the colour.
        var $environmentIndicator = $('#environment-indicator', context);
        $environmentIndicator.css('background-color', settings.environment_indicator.color);
        
        // Make the text appear vertically
        $environmentIndicator.html($environmentIndicator.text().replace(/(.)/g,"$1<br />"));
        
        // Adjust the margin.
        if (settings.environment_indicator.margin) {
          $('body:not(.environment-indicator-adjust)', context).addClass('environment-indicator-adjust');
 
          // Adjust the width of the toolbar
          if ($("#toolbar").length) {
            $("#toolbar").css('margin-'+settings.environment_indicator.position, '10px');
          }
        }
      }
      $('body:not(.environment-indicator-processed)', context).addClass('environment-indicator-processed');
    }
  }
};
  
})(jQuery);
