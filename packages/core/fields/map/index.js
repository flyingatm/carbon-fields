// Import required dependencies and styles
import { Component, Fragment, createRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { debounce } from 'lodash';
import './style.scss';
import GoogleMap from './google-map';

class MapField extends Component {
	// Create a reference for the autocomplete input
	autocompleteInputRef = createRef();

	componentDidMount() {
		// Initialize the Google Places Autocomplete
		this.initAutocomplete();
	}

	// Handler for debouncing search input change
	handleSearchChange = debounce((address) => {
		if (address) {
			this.props.onGeocodeAddress({ address });
		}
	}, 250);

	// Handle the selection of a place from the Autocomplete
	handlePlaceSelect = (place) => {
		const { geometry, place_id, name } = place;
		const lat = geometry.location.lat();
		const lng = geometry.location.lng();

		this.props.onChange(this.props.id, {
			...this.props.value,
			place_id,
			name,
			lat,
			lng
		});

		// Update the map component with new location
		this.mapComponent.updateMap({
			lat,
			lng,
		});
	};

	// Initialize the Google Places Autocomplete
	initAutocomplete() {
		const input = this.autocompleteInputRef.current;
		const autocomplete = new window.google.maps.places.Autocomplete(input, {
			fields: ["name", "place_id", "geometry"]
		});

		// Prevent form submission on Enter key press in the Autocomplete input
		input.addEventListener('keydown', (event) => {
			if (event.key === 'Enter') {
				event.preventDefault();
			}
		});

		// Handle the selection of a place from Autocomplete dropdown
		autocomplete.addListener('place_changed', () => {
			const place = autocomplete.getPlace();
			if (place.geometry) {
				this.handlePlaceSelect(place);
			}
		});
	}


	render() {
		const { id, name, value } = this.props;

		return (
			<Fragment>
				{/* Hidden input fields for place_id, name, lat, lng, and zoom */}
				<input
					type="hidden"
					name={`${name}[place_id]`}
					value={value.place_id || ''}
				/>
				<input
					type="hidden"
					name={`${name}[name]`}
					value={value.name || ''}
				/>
				<input
					type="hidden"
					name={`${name}[lat]`}
					value={value.lat || ''}
				/>
				<input
					type="hidden"
					name={`${name}[lng]`}
					value={value.lng || ''}
					readOnly
				/>
				<input
					type="hidden"
					name={`${name}[zoom]`}
					value={value.zoom || ''}
					readOnly
				/>
				{/* Autocomplete input */}
				<div className="cf-search-input dashicons-before dashicons-search">
					<input
						id={id}
						type="text"
						ref={this.autocompleteInputRef}
						className="cf-map__search cf-search-input__inner"
						defaultValue={value.name || ''}
						placeholder="Search for a place"
					/>
				</div>
				{/* GoogleMap component */}
				<GoogleMap
					ref={(map) => (this.mapComponent = map)}
					className="cf-map__canvas"
					lat={value.lat}
					lng={value.lng}
					zoom={value.zoom}
					onChange={this.handleMapChange}
				/>
			</Fragment>
		);
	}
}

// Export the MapField component
export default MapField;
