import { useTranslation } from '../../../lib/useTranslation';
import Dialog from './Dialog';

export default function DocumentPreview({
  file,
  onClose,
}: {
  file: { name: string; type: string; url: string };
  onClose: () => void;
}) {
  const t = useTranslation();
  return (
    <Dialog title={file.name} onClose={onClose} className="sf-document-dialog">
      {file.type === 'pdf' ? (
        <iframe
          title={file.name}
          src={`${file.url}#navpanes=0&view=FitH`}
          className="sf-document-preview"
        />
      ) : ['jpg', 'jpeg', 'png', 'webp'].includes(file.type) ? (
        <img className="sf-document-image" src={file.url} alt={file.name} />
      ) : (
        <p>{t('storefront.document_no_preview')}</p>
      )}
      <a
        href={file.url}
        target="_blank"
        rel="noopener"
        className="sf-document-fallback"
      >
        {t('storefront.document_open_new')}
      </a>
    </Dialog>
  );
}
