import { inertia } from '@statamic/cms/api';

import MyTerritories from './pages/delegated/MyTerritories.vue';
import Territories from './pages/delegated/Territories.vue';

/**
 * The addon's control panel assets.
 *
 * Statamic resolves an Inertia page by name: its own pages first, then this
 * registry. The controller renders `delegated/Territories`; this is where
 * that name gets a component. The control panel layout is applied for us.
 */
inertia.register('delegated/Territories', Territories);
inertia.register('delegated/MyTerritories', MyTerritories);
