import './preview.css';
import '../src/client/styles.css';
import { createRoot } from 'react-dom/client';
import { QueryClientProvider } from '@tanstack/react-query';
import { queryClient } from '@pterodactyl/sdk';
import AppToaster from '@/components/elements/AppToaster';
import OAuthClientsScreen from '../src/client/screens/OAuthClientsScreen';
import { installMockApi } from './mock';

installMockApi();

createRoot(document.getElementById('root')!).render(
    <QueryClientProvider client={queryClient}>
        <main className='mx-auto w-full max-w-[72rem] px-4 py-6'>
            <OAuthClientsScreen />
        </main>
        <AppToaster />
    </QueryClientProvider>
);
