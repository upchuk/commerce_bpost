(function($, Drupal, drupalSettings) {

  Drupal.behaviors.map = {
    attach: function(context, settings) {
      $('.bpost-poi-link').each(function(index, item) {
        var $element = $(item);
        $element.once().click(function(e) {
          e.preventDefault();
          var lat = $element.attr('data-poi-lat');
          var lon = $element.attr('data-poi-lon');
          var latlon = L.latLng(lat, lon);
          settings.leaflet['bpost-map'].lMap.flyTo(latlon, 17);
          removePoiSelections();
          $element.addClass('selected-poi');
          $('.selected-pickup-point').trigger('pickup-point-selection', [$element.attr('data-poi-id')]);
        });
      });

      $('.leaflet-marker-icon').each(function(index, item) {
        var $marker = $(item);
        $marker.once().on('click', function(e) {
          removePoiSelections();

          var $poi_id = $($marker.find('i')[0]).attr('data-poi-id');
          $("a[data-poi-id='" + $poi_id + "']").addClass('selected-poi');
          $(".bpost-pickup-point-list .selected-poi")[0].scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'start' });
          $('.selected-pickup-point').trigger('pickup-point-selection', [$poi_id]);
        });
      });

      $('.selected-pickup-point').on("pickup-point-selection", function(e, poi_id) {
        var details = settings.commerce_bpost_pickup.pickup_point_details[poi_id];
        updateSelectedPoint(poi_id, details);
      });

      /**
       * Removes all the POI selections: class and radio selection.
       */
      function removePoiSelections() {
        $('.bpost-poi-link').each(function(i, link) {
          $(link).removeClass('selected-poi');
        });

        $('input.poi-input').each(function(i, input) {
          $(input).attr('value', 0);
        });
      }

      /**
       * Updates the selected element with the POI details.
       *
       * @param details
       */
      function updateSelectedPoint(poi_id, details) {
        $($(["input[data-poi-id='" + poi_id + "']"])[0]).attr('value', 1);
        var $selected = $('.selected-pickup-point');
        var html = $('<div></div>');
        $selected.removeClass('hide');
        html.append('<p>' + Drupal.t('Your selection:') + '</p>');
        html.append('<p><strong>' + details.label + '</strong></p>');
        html.append('<p>' + details.address + '</p>');
        $selected.html(html.html());
      }
    }
  };



})(jQuery, Drupal, drupalSettings);
