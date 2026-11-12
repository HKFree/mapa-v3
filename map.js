(() => {
  'use strict';

  const LABEL_ZOOM_THRESHOLD = 14;

  function createMap() {
    const bkAttr = ' | Written by <a href="mailto:bkralik@hkfree.org">bkralik</a>';



    const mbAttr = 'Map data &copy; <a href="https://www.openstreetmap.org/">OpenStreetMap</a> contributors, ' +
        '<a href="https://creativecommons.org/licenses/by-sa/2.0/">CC-BY-SA</a>, ' +
        'Imagery © <a href="https://www.mapbox.com/">Mapbox</a>' + bkAttr;
    const mbUrl = 'https://api.mapbox.com/styles/v1/{id}/tiles/{z}/{x}/{y}?access_token=REMOVED_MAPBOX_TOKEN';


    const osmAttr = 'Map data &copy; <a href="https://www.openstreetmap.org/">OpenStreetMap</a> contributors, ' +
        '<a href="https://creativecommons.org/licenses/by-sa/2.0/">CC-BY-SA</a>' + bkAttr;

    const osmUrl = 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png';

    const mapbox = L.tileLayer(mbUrl, {id: 'mapbox/streets-v11', attribution: mbAttr, tileSize: 512, zoomOffset: -1});
    const mapboxSatellite = L.tileLayer(mbUrl, {id: 'mapbox/satellite-streets-v11', attribution: mbAttr, tileSize: 512, zoomOffset: -1});
    const osm = L.tileLayer(osmUrl, {attribution: osmAttr});

    const map = L.map('map', {
      preferCanvas: true,
      center: [50.2093261, 15.8333789],
      zoom: 12,
      layers: [mapbox]
    });

    L.control.scale({maxWidth: 200, metric: true, imperial: false, position: 'bottomleft'}).addTo(map);
    L.control.polylineMeasure({
      position: 'topleft',
      unit: 'metres',
      showBearings: true,
      clearMeasurementsOnStop: false,
      showClearControl: true,
      showUnitControl: false
    }).addTo(map);
    L.control.layers({
      'OpenStreetMaps': osm,
      'MapBox Streets': mapbox,
      'MapBox Satellite': mapboxSatellite
    }).addTo(map);

    return map;
  }

  function createMarkerContent(name) {
    const content = document.createElement('div');
    const image = document.createElement('img');
    image.className = 'point-image';
    image.src = 'icons/node_2_1.png';
    image.alt = '';

    const label = document.createElement('span');
    label.className = 'point-name';
    label.textContent = name;

    content.append(image, label);
    return content;
  }

  function createPopupContent(area, name) {
    const content = document.createElement('div');
    const title = document.createElement('b');
    title.textContent = name;

    const admins = Object.values(area.admins);
    const adminDescription = admins.length > 0
      ? 'Správci: ' + admins.map(admin => admin.nick).join(', ')
      : 'Oblast nemá správce.';

    const contact = document.createElement('a');
    contact.href = 'mailto:' + area.email;
    contact.textContent = area.email;

    content.append(
      title,
      document.createElement('br'),
      document.createTextNode(adminDescription),
      document.createElement('br'),
      document.createTextNode('Kontakt: '),
      contact
    );
    return content;
  }

  function createMarker(map, area, accessPoint) {
    if (!accessPoint.gps) {
      return;
    }

    const coordinates = accessPoint.gps.split(',');
    const marker = L.marker(coordinates, {
      icon: new L.DivIcon({
        className: 'point-flex',
        html: createMarkerContent(accessPoint.jmeno),
        iconSize: [30, 36],
        iconAnchor: [15, 28]
      })
    }).addTo(map);

    marker.bindPopup(createPopupContent(area, accessPoint.jmeno));
  }

  function updateLabelVisibility(map) {
    map.getContainer().classList.toggle('show-point-names', map.getZoom() > LABEL_ZOOM_THRESHOLD);
  }

  function loadPoints(map) {
    $.getJSON('proxy.php')
      .done(areas => {
        $.each(areas, (_areaKey, area) => {
          $.each(area.aps, (_pointKey, accessPoint) => {
            createMarker(map, area, accessPoint);
          });
        });
        updateLabelVisibility(map);
      })
      .fail(() => {
        $('#map-error')
          .text('Body sítě se nepodařilo načíst. Zkuste prosím obnovit stránku.')
          .prop('hidden', false);
      });
  }

  const map = createMap();
  map.on('zoomend', () => updateLabelVisibility(map));
  updateLabelVisibility(map);
  loadPoints(map);
})();
