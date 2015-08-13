var Poetica = (function() {

    var Poetica = function(data) {
        jQuery(document).ready(this.onDocumentReady(this));
        
        this.docDomain = data.docDomain;
        this.tinyMCEUrl = data.tinyMCEUrl;
        this.poeticaDomain = data.poeticaDomain;
        this.group_auth = data.group_auth;
        this.user_auth = data.user_auth;
        this.poeticaLocation = data.poeticaLocation;
        this.groupDomain = data.groupDomain;
        this.submitted = false;
        jQuery(function() {
          // So we  can just toggle later
          jQuery('body').addClass('focus-off');
        });
    }
    
    Poetica.prototype.onDocumentReady = function(_this) { return function() {
        if (_this.poeticaLocation) {
            jQuery('#poetica-tinymce').click(_this.onTinyMCEClick(_this));
            jQuery('#save-post, #publish, #post-preview').click(_this.onSavePublishPreview(_this));
        }
        jQuery('#poetica-group-link').click(_this.onGroupLink(_this));
        jQuery('#poetica-user-link').click(_this.onUserLink(_this));
        jQuery('#poetica-dfw').click(_this.onFullScreen(_this));
        if(jQuery('#slack').length > 0) {
          _this.slackMessageProxy(_this);
        }
    }}

    Poetica.prototype.onSavePublishPreview = function(_this){ return function(clickEvent) {
      if(!_this.submitted) {
        clickEvent.preventDefault();
  
        var listener = function(messageEvent){
          if (messageEvent.data != 'committed') return;
          window.removeEventListener('message', listener);
  
          _this.submitted = true;
          // Resubmit to finish
          jQuery(clickEvent.currentTarget).click();
        };
        window.addEventListener('message', listener);
  
        var iframe = jQuery('.poetica-iframe')[0];
        iframe.contentWindow.postMessage('commit', _this.docDomain);
      } else {
        _this.submitted = false;
      }
    }}

    Poetica.prototype.onTinyMCEClick = function(_this) { return function(clickEvent) {
        var listener = function(messageEvent) {
            window.removeEventListener('message', listener);
            if (messageEvent.data != 'finish_convert_to_tinymce') return;
            window.location = _this.tinyMCEUrl;
        };
        window.addEventListener('message', listener);
        var iframe = jQuery('.poetica-iframe')[0];
        iframe.contentWindow.postMessage('tinyMCE', _this.docDomain);
        return false;
    }};

    Poetica.prototype.onGroupLink = function(_this) { return function(clickEvent) {
        var data = {
            verification_token: _this.group_auth.verification_token,
            url: _this.group_auth.verifyUrl
        };

        jQuery.post(_this.poeticaDomain + '/api/wordpress/group', data,
          function (group) {
            data = _this.user_auth;
            data['group_access_token'] = group.wordpress_plugin.access_token;

            jQuery.post( _this.poeticaDomain + '/api/wordpress/user', data, function (user) {
                window.location = _this.group_auth.saveUrl + '?group_access_token=' + group.wordpress_plugin.access_token +
                    '&group_subdomain=' + group.subdomain +
                    '&user_access_token=' + user.wordpress.accessToken.token;
            });
        });
    }};

    Poetica.prototype.onUserLink = function(_this) { return function(clickEvent) {
        clickEvent.preventDefault();
        var data = _this.user_auth;
        jQuery.post( _this.poeticaDomain + '/api/wordpress/user', data, function (user) {
          window.location = _this.group_auth.saveUrl + '?user_access_token=' + user.wordpress.accessToken.token + '&redirect=' + encodeURIComponent(window.location);
        });
        return false;
    }};

    Poetica.prototype.onFullScreen = function(_this) { return function(clickEvent) {
      clickEvent.preventDefault();
      jQuery('body').toggleClass('focus-on');
      jQuery('body').toggleClass('focus-off');

      jQuery('#post-body-content').mouseleave(function(){
        jQuery('body').addClass('focus-off');
        jQuery('body').removeClass('focus-on');
        jQuery('#post-body-content').unbind("mouseleave");
      });
    }};

    Poetica.prototype.slackMessageProxy = function(_this) {
      var listener = function(messageEvent){
        var iframe = jQuery('.poetica-iframe')[0];
        iframe.contentWindow.postMessage(messageEvent.data, _this.groupDomain);
      };
      window.addEventListener('message', listener);
    };


    return Poetica;
})();
