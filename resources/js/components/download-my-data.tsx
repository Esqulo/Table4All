import { Download } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';

/** A copy of everything kept about the person, as a JSON file (LGPD Art. 18 II and V). */
export default function DownloadMyData() {
    const { t } = useTranslation();

    return (
        <div className="space-y-6">
            <Heading
                variant="small"
                title={t('settings.my_data.title')}
                description={t('settings.my_data.description')}
            />
            <Button variant="outline" asChild>
                {/* A plain link: the browser downloads the file, Inertia must not follow it. */}
                <a href={ProfileController.export.url()} download>
                    <Download aria-hidden="true" />
                    {t('settings.my_data.download')}
                </a>
            </Button>
        </div>
    );
}
