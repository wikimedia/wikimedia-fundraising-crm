(function($, _) {
  var mainDiv = $('<div class="civirpow-container"/>').appendTo($('body'));

  var detailsTpl = _.template('<div>' +
    '<div><strong>Cookie</strong>: <%= rpow.name %></div>' +
    '<div>' +
    '  <strong>Expires</strong>: <%= rpow.exp - now %>' +
    '  <a href="#" title="Expire Now" class="civirpow-rm"><i class="crm-i fa-trash"></i></a>' +
    '</div>' +
    '<div><strong>Buffer</strong>:</div>' +
    '<pre><%= rpow.cause %></pre>' +
    '</div>');
  function makeDetails(rpows) {
    return $('<div/>').append(
      _.map(rpows, function(rpow){
        var node = $(detailsTpl({
          rpow: rpow,
          now: getEpochTime()
        }));
        node.find('.civirpow-rm').click(function(){
          deleteCookie(rpow.name);
          setTimeout(refresh, 1);
          return false;
        });
        return node;
      })
    )
  }

  function deleteCookie(name) {
    document.cookie = name+'=; expires=Thu, 01 Jan 1970 00:00:00 GMT; Max-Age=-99999999; path=/';
  }

  function decode(v) { return JSON.parse(decodeURIComponent(v.replace(/\+/g, '%20'))); }
  function getEpochTime() { return Math.floor((new Date()).valueOf() / 1000); }

  /** Read all cookies matching "rpow______"; decode JSON content */
  function getRpows() {
    var rpows = [];
    _.each(document.cookie.split(/;\s*/), function(line){
      var parts = line.split('=');
      if (parts[0].startsWith('rpow')) {
        var obj= decode(parts[1]);
        obj.name = parts[0];
        rpows.push(obj);
      }
    });
    return rpows;
  }

  function refresh() {
    var rpows = getRpows();
    if (rpows.length > 0) {
      mainDiv.empty()
        .append('<h4>RWDB Preferred</h4>')
        .append(makeDetails(rpows))
        .toggleClass('civirpow-ro', false)
        .toggleClass('civirpow-rw', true);
    }
    else {
      mainDiv.empty()
        .append('<h4>RODB Preferred</h4>')
        .toggleClass('civirpow-ro', true)
        .toggleClass('civirpow-rw', false);
    }
  }
  
  refresh();
  setInterval(refresh, 500);

})(CRM.$, CRM._);
