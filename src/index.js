import { createRoot } from '@wordpress/element';
import App from './app';
// DataViews has no core-registered style handle, so bundle its stylesheet into
// our single compiled build/style-index.css rather than shipping a copy. The
// path is relative because the package's "exports" map blocks bare-specifier
// access to build-style/; webpack.config.js re-flags it as having side effects
// so it isn't tree-shaken out of the production build.
import '../node_modules/@wordpress/dataviews/build-style/style.css';
import './style.scss';

const container = document.getElementById( 'filtered-calendars-root' );
if ( container ) {
	createRoot( container ).render( <App /> );
}
