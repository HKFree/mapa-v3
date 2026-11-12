(() => {
  'use strict';

  const LABEL_ZOOM_THRESHOLD = 14;
  const MAX_ZOOM = 18;
  const DEFAULT_CENTER = [50.2093261, 15.8333789];
  const DEFAULT_ZOOM = 12;
  const LAYER_KEYS = ['osm', 'streets', 'satellite', 'light'];

  function readUrlState() {
    const params = new URLSearchParams(window.location.hash.slice(1));
    const parts = (params.get('map') || '').split('/');
    const [zoom, lat, lng] = parts.map(Number);
    const validView = parts.length === 3 && parts.every(part => part.trim() !== '') &&
      Number.isFinite(zoom) && zoom >= 0 && zoom <= MAX_ZOOM &&
      Number.isFinite(lat) && Math.abs(lat) <= 90 &&
      Number.isFinite(lng) && Math.abs(lng) <= 180;

    return {
      view: validView ? {center: [lat, lng], zoom} : null,
      layer: LAYER_KEYS.includes(params.get('layer')) ? params.get('layer') : 'streets',
      hasLayer: LAYER_KEYS.includes(params.get('layer'))
    };
  }

  function syncUrlState(map, layers, initialState) {
    let includeLayer = initialState.hasLayer;
    let restoringUrl = false;

    function updateUrl() {
      if (restoringUrl) {
        return;
      }
      const center = map.getCenter().wrap();
      const layer = LAYER_KEYS.find(key => map.hasLayer(layers[key]));
      const url = new URL(window.location.href);
      const zoom = Number(map.getZoom().toFixed(2));
      url.hash = `map=${zoom}/${center.lat.toFixed(6)}/${center.lng.toFixed(6)}` +
        (includeLayer ? `&layer=${layer}` : '');
      if (url.href !== window.location.href) {
        window.history.replaceState(window.history.state, '', url);
      }
      map.getContainer().classList.toggle('satellite-map', layer === 'satellite');
    }

    map.on('moveend', updateUrl);
    map.on('baselayerchange', () => {
      if (!restoringUrl) {
        includeLayer = true;
      }
      updateUrl();
    });
    window.addEventListener('hashchange', () => {
      const state = readUrlState();
      restoringUrl = true;
      includeLayer = state.hasLayer;
      if (state.view) {
        map.setView(state.view.center, state.view.zoom, {animate: false});
      }
      for (const key of LAYER_KEYS) {
        if (key !== state.layer && map.hasLayer(layers[key])) {
          map.removeLayer(layers[key]);
        }
      }
      layers[state.layer].addTo(map);
      restoringUrl = false;
      updateUrl();
    });
    updateUrl();
  }

  function createMap(initialState) {
    const bkAttr = ' | <a href="mailto:bkralik@hkfree.org" title="Autor mapy">bkralik</a>';
    const osmCredits = '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>';
    const mbAttr = '&copy; <a href="https://www.mapbox.com/about/maps/">Mapbox</a> | ' +
      osmCredits + ' | <a href="https://apps.mapbox.com/feedback/">Improve this map</a>' + bkAttr;
    const {mapboxToken} = JSON.parse(document.getElementById('map-config').textContent);
    const mbUrl = 'https://api.mapbox.com/styles/v1/{id}/tiles/{z}/{x}/{y}?access_token=' +
      encodeURIComponent(mapboxToken);


    const osmAttr = osmCredits + bkAttr;

    const osmUrl = 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png';

    const mapbox = L.tileLayer(mbUrl, {id: 'mapbox/streets-v11', attribution: mbAttr, tileSize: 512, zoomOffset: -1});
    const mapboxSatellite = L.tileLayer(mbUrl, {id: 'mapbox/satellite-streets-v11', attribution: mbAttr, tileSize: 512, zoomOffset: -1});
    const mapboxLight = L.tileLayer(mbUrl, {id: 'mapbox/light-v11', attribution: mbAttr, tileSize: 512, zoomOffset: -1});
    const osm = L.tileLayer(osmUrl, {attribution: osmAttr});
    const layers = {osm, streets: mapbox, satellite: mapboxSatellite, light: mapboxLight};

    const map = L.map('map', {
      preferCanvas: true,
      center: initialState.view ? initialState.view.center : DEFAULT_CENTER,
      zoom: initialState.view ? initialState.view.zoom : DEFAULT_ZOOM,
      minZoom: 0,
      maxZoom: MAX_ZOOM,
      zoomSnap: 0.01,
      layers: [layers[initialState.layer]]
    });

    const scale = L.control.scale({maxWidth: 200, metric: true, imperial: false, position: 'bottomleft'}).addTo(map);
    avoidScaleOverlap(map, scale);
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
      'MapBox Satellite': mapboxSatellite,
      'MapBox Light': mapboxLight
    }).addTo(map);

    addLogo(map);
    syncUrlState(map, layers, initialState);
    return map;
  }

  function addHomeButton(map, resetView) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'map-home';
    button.title = 'Zobrazit všechny body';
    button.setAttribute('aria-label', button.title);
    button.disabled = true;

    const icon = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    icon.setAttribute('viewBox', '0 0 24 24');
    icon.setAttribute('width', '16');
    icon.setAttribute('height', '16');
    icon.setAttribute('aria-hidden', 'true');
    const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    path.setAttribute('d', 'M3 10.5 12 3l9 7.5M5 9v12h5v-7h4v7h5V9');
    path.setAttribute('fill', 'none');
    path.setAttribute('stroke', 'currentColor');
    path.setAttribute('stroke-width', '2');
    path.setAttribute('stroke-linecap', 'round');
    path.setAttribute('stroke-linejoin', 'round');
    icon.append(path);
    button.append(icon);

    L.DomEvent.disableClickPropagation(button);
    L.DomEvent.on(button, 'click', event => {
      L.DomEvent.stop(event);
      resetView();
    });
    map.zoomControl.getContainer().append(button);
    return button;
  }

  function showDefaultView(map, bounds) {
    if (bounds.isValid()) {
      map.fitBounds(bounds, {
        // Include the marker dimensions and anchor in the 20px visual margin.
        paddingTopLeft: [35, 48],
        paddingBottomRight: [35, 28],
        maxZoom: 16,
        animate: false
      });
    } else {
      map.setView(DEFAULT_CENTER, DEFAULT_ZOOM, {animate: false});
    }
  }

  function avoidScaleOverlap(map, scale) {
    const element = scale.getContainer();
    const attribution = map.attributionControl.getContainer();
    let lift = 0;

    function updatePosition() {
      const scaleRect = element.getBoundingClientRect();
      const attributionRect = attribution.getBoundingClientRect();
      const overlapsHorizontally = scaleRect.right + 6 > attributionRect.left &&
        scaleRect.left < attributionRect.right + 6;
      // Add the current lift back to measure the scale's original bottom position.
      const nextLift = overlapsHorizontally
        ? Math.ceil(Math.max(0, scaleRect.bottom + lift - attributionRect.top + 6))
        : 0;
      if (nextLift !== lift) {
        lift = nextLift;
        element.style.transform = lift ? `translateY(-${lift}px)` : '';
      }
    }

    const observer = new ResizeObserver(updatePosition);
    observer.observe(map.getContainer());
    observer.observe(attribution);
    observer.observe(element);
    map.once('unload', () => observer.disconnect());
    updatePosition();
  }

  function addLogo(map) {
    const element = document.querySelector('.map-logo');
    const control = L.control({position: 'topleft'});
    control.onAdd = () => element;
    control.addTo(map);
    element.parentElement.prepend(element);

    element.addEventListener('animationend', event => {
      if (event.animationName === 'hide-map-logo') {
        element.hidden = true;
      }
    });
    window.matchMedia('(max-width: 600px)').addEventListener('change', () => {
      element.hidden = false;
    });
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
    return marker;
  }

  function updateLabelVisibility(map) {
    map.getContainer().classList.toggle('show-point-names', map.getZoom() > LABEL_ZOOM_THRESHOLD);
  }

  function loadPoints(map, shouldFitBounds, bounds) {
    return $.getJSON('proxy.php')
      .done(areas => {
        $.each(areas, (_areaKey, area) => {
          $.each(area.aps, (_pointKey, accessPoint) => {
            const marker = createMarker(map, area, accessPoint);
            if (marker) {
              bounds.extend(marker.getLatLng());
            }
          });
        });
        if (shouldFitBounds() && bounds.isValid()) {
          showDefaultView(map, bounds);
        }
        updateLabelVisibility(map);
      })
      .fail(() => {
        $('#map-error')
          .text('Body sítě se nepodařilo načíst. Zkuste prosím obnovit stránku.')
          .prop('hidden', false);
      });
  }

  const initialState = readUrlState();
  const map = createMap(initialState);
  const bounds = L.latLngBounds([]);
  const homeButton = addHomeButton(map, () => showDefaultView(map, bounds));
  let fitInitialBounds = !initialState.view;
  map.once('movestart', () => { fitInitialBounds = false; });
  map.on('zoomend', () => updateLabelVisibility(map));
  updateLabelVisibility(map);
  loadPoints(map, () => fitInitialBounds, bounds).always(() => {
    homeButton.disabled = false;
  });
})();
