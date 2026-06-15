import { useEffect } from 'react';
import { Fancybox } from '@fancyapps/ui';
import '@fancyapps/ui/dist/fancybox/fancybox.css';

/**
 * Binds Fancybox to a container ref.
 * Cleans up properly on unmount.
 */
export default function useFancybox(containerRef) {
  useEffect(() => {
    const container = containerRef?.current;
    if (!container) return;

    Fancybox.bind(container, '[data-fancybox]', {
      animated: true,
      showClass: 'fancybox-fadeIn',
      hideClass: 'fancybox-fadeOut',
      Images: { zoom: true },
      Toolbar: {
        display: {
          left:  ['infobar'],
          middle: [],
          right: ['download', 'close'],
        },
      },
    });

    return () => {
      Fancybox.unbind(container);
      Fancybox.close();
    };
  }, [containerRef]);
}
