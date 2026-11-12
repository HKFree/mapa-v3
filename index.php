<!DOCTYPE html>
<html>
<head>
  <title>HKFree.org integrovaná mapa</title>

  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="shortcut icon" type="image/x-icon" href="favicon.ico">

  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
     integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY="
     crossorigin=""/>

  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
     integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
     crossorigin=""></script>

  <script
        src="https://code.jquery.com/jquery-3.7.1.js"
        integrity="sha256-eKhayi8LEQwp4NKxN+CfCh+3qOVUtJn3QNZ0TciWLP4="
        crossorigin="anonymous"></script>

  <link rel="stylesheet" href="https://ppete2.github.io/Leaflet.PolylineMeasure/Leaflet.PolylineMeasure.css" />
  <script src="https://ppete2.github.io/Leaflet.PolylineMeasure/Leaflet.PolylineMeasure.js"></script>

  <style>
    html, body {
      height: 100%;
      margin: 0;
    }
    #map {
      width: 100%;
      height: 100%;
    }
    .point-name {
      /* nice shadow generator: https://owumaro.github.io/text-stroke-generator/ */
      color: black;
      text-shadow: rgb(255, 255, 255) 2px 0px 0px, rgb(255, 255, 255) 1.75517px 0.958851px 0px, rgb(255, 255, 255) 1.0806px 1.68294px 0px, rgb(255, 255, 255) 0.141474px 1.99499px 0px, rgb(255, 255, 255) -0.832294px 1.81859px 0px, rgb(255, 255, 255) -1.60229px 1.19694px 0px, rgb(255, 255, 255) -1.97999px 0.28224px 0px, rgb(255, 255, 255) -1.87291px -0.701566px 0px, rgb(255, 255, 255) -1.30729px -1.51361px 0px, rgb(255, 255, 255) -0.421592px -1.95506px 0px, rgb(255, 255, 255) 0.567324px -1.91785px 0px, rgb(255, 255, 255) 1.41734px -1.41108px 0px, rgb(255, 255, 255) 1.92034px -0.558831px 0px;
    }
    .point-flex {
      display: flex;
      flex-flow: column;
      align-items: center;
    }
  </style>
</head>
<body>
<div id="map"></div>

<script>

  var bkAttr = ' | Written by <a href="mailto:bkralik@hkfree.org">bkralik</a>';


  //var wmAttr = '<a href="https://wikimediafoundation.org/wiki/Maps_Terms_of_Use">Wikimedia Maps</a>, ' +
  //    'Map data &copy; <a href="https://www.openstreetmap.org/">OpenStreetMap</a> contributors, ' +
  //    '<a href="https://creativecommons.org/licenses/by-sa/2.0/">CC-BY-SA</a>' + bkAttr;
  //var wmUrl = 'https://maps.wikimedia.org/osm-intl/{z}/{x}/{y}.png ';

  var mbAttr = 'Map data &copy; <a href="https://www.openstreetmap.org/">OpenStreetMap</a> contributors, ' +
      '<a href="https://creativecommons.org/licenses/by-sa/2.0/">CC-BY-SA</a>, ' +
      'Imagery © <a href="https://www.mapbox.com/">Mapbox</a>' + bkAttr;
  var mbUrl = 'https://api.mapbox.com/styles/v1/{id}/tiles/{z}/{x}/{y}?access_token=REMOVED_MAPBOX_TOKEN';


  var osmAttr = 'Map data &copy; <a href="https://www.openstreetmap.org/">OpenStreetMap</a> contributors, ' +
      '<a href="https://creativecommons.org/licenses/by-sa/2.0/">CC-BY-SA</a>' + bkAttr;

  var osmUrl = 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png';

  //var wm = L.tileLayer(wmUrl, {attribution: wmAttr});
  var mapbox = L.tileLayer(mbUrl, {id: 'mapbox/streets-v11', attribution: mbAttr, tileSize: 512, zoomOffset: -1});
  var mapbox_satellite = L.tileLayer(mbUrl, {id: 'mapbox/satellite-streets-v11', attribution: mbAttr, tileSize: 512, zoomOffset: -1});
  var osm = L.tileLayer(osmUrl, {attribution: osmAttr});


  var map = L.map('map', {
    preferCanvas: true,
    center: [50.2093261, 15.8333789],
    zoom: 12,
    layers: [mapbox]
  });

  L.control.scale ({maxWidth:200, metric:true, imperial:false, position: 'bottomleft'}).addTo (map);

  let polylineMeasure = L.control.polylineMeasure ({position:'topleft', unit:'metres', showBearings:true, clearMeasurementsOnStop: false, showClearControl: true, showUnitControl: false})
  polylineMeasure.addTo (map);

  var baseLayers = {
  //  "Wikimedia maps": wm,
    "OpenStreetMaps": osm,
    "MapBox Streets": mapbox,
    "MapBox Satellite": mapbox_satellite
  };

  L.control.layers(baseLayers).addTo(map);


  var mapMarkers = [];

  $.getJSON( "proxy.php", function( oblasti ) {
    $.each( oblasti, function( key, oblast ) {
      $.each( oblast["aps"], function ( key2, ap ) {
        if(!ap["gps"]) {
          return;
        }
        souradnice = ap["gps"].split(",");

        m = L.marker(souradnice, {icon: new L.DivIcon({
          className: 'point-flex',
          html: '<img class="point-image" src="icons/node_2_1.png"/>'+
                '<span class="point-name" style="width: max-content;">'+ap["jmeno"]+'</span>',
          iconSize: [30, 36],
          iconAnchor: [15, 28]
        })}).addTo(map);

        omail = "oblast" + this["id"] + "@hkfree.org";
        if (Object.keys(this["admins"]).length > 0) {
          ospravci = "Správci: " + Object.values(this["admins"]).map((ele) => ele["nick"]).join(", ");
        } else {
          ospravci = "Oblast nemá správce.";
        }
        m.bindPopup('<b>' + ap["jmeno"] + '</b><br>' + ospravci + '<br>Kontakt: <a href="mailto:' + omail + '">' + omail + '</a>');

        mapMarkers.push(m);
      }.bind(oblast));
    });
    $(".point-name").hide(0);
  });

var pointsHidden = 1;

map.on('zoomend', function(ev) {
    var zoom = ev.target.getZoom();
    if(zoom > 14) {
      if(pointsHidden == 1) {
        pointsHidden = 0;
        $(".point-name").fadeIn(duration=400);
      }
    } else {
      if(pointsHidden == 0) {
        pointsHidden = 1;
        $(".point-name").fadeOut(duration=400);
      }
    }
});

</script>

</body>
</html>


