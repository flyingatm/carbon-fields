// Import required dependencies
import observeResize from 'observe-resize';
import { Component, createRef } from '@wordpress/element';

class GoogleMap extends Component {
    // Create a reference for the map container
    node = createRef();

    componentDidMount() {
        // Set up the map and initial render
        this.setupMap();
        this.updateMap(this.props);

        // Observe resize events for map
        this.cancelResizeObserver = observeResize(this.node.current, () => {
            this.updateMap(this.props);
        });
    }

    componentDidUpdate() {
        // Update the map on component update
        this.updateMap(this.props);
    }

    componentWillUnmount() {
        // Clean up on component unmount
        this.cancelResizeObserver();
        window.google.maps.event.clearInstanceListeners(this.map);
    }

    // Set up the Google Map
    setupMap() {
        const {
            lat,
            lng,
            zoom
        } = this.props;

        const position = new window.google.maps.LatLng(lat, lng);

        // Create the map
        this.map = new window.google.maps.Map(this.node.current, {
            zoom,
            center: position,
            mapTypeId: window.google.maps.MapTypeId.ROADMAP,
            scrollwheel: false
        });

        // Add a marker to the map
        this.marker = new window.google.maps.Marker({
            position,
            map: this.map
        });
    }

    // Update the map's position
    updateMap(props) {
        const { lat, lng } = props;
        const location = new window.google.maps.LatLng(lat, lng);

        setTimeout(() => {
            // Trigger map resize and center it on the new location
            window.google.maps.event.trigger(this.map, 'resize');
            this.map.setCenter(location);
        }, 10);
    }

    render() {
        return (
            <div ref={this.node} className={this.props.className}></div>
        );
    }
}

// Export the GoogleMap component
export default GoogleMap;
