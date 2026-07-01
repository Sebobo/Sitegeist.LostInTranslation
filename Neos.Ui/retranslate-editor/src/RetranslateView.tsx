import React, {useCallback, useState, useMemo} from 'react';
import {useMutation} from '@tanstack/react-query';
import {useI18n} from '@sitegeist/lostintranslation-neos-bridge';
import {endpoints} from './hooks/backend';
import {useContentInfo} from './hooks/useContentInfo';
import {useNodeInfo} from './hooks/useNodeInfo';
import {Button, Dialog, SelectBox} from '@neos-project/react-ui-components'
import {Container, Info, LoadingContainer, Spinner, ButtonsContainer, DialogContent} from './components';

type RetranslateViewTarget = 'node' | 'document';

type RetranslateViewProps = {
    for: RetranslateViewTarget;
};

type ResultDialogState = {
    type: 'success' | 'warn' | 'error';
    title: string;
    message: string;
};

export const RetranslateView = ({for: target}: RetranslateViewProps) => {
    const t = useI18n();
    const nodeInfo = useNodeInfo(target);
    const {
        data: contentData,
        isLoading
    } = useContentInfo(nodeInfo.nodeId, nodeInfo.workspace, nodeInfo.dimensions, nodeInfo.contentRepositoryId);

    const [selectedTarget, setSelectedTarget] = useState<string | null>(null);
    const [resultDialog, setResultDialog] = useState<ResultDialogState | null>(null);
    const [activeButton, setActiveButton] = useState<'translate' | 'force' | null>(null);

    const translateMutation = useMutation({
        mutationFn: async (params: { targetCoordinates: Record<string, string>; force?: boolean }) => {
            return endpoints().translate({
                nodeAggregateId: nodeInfo.nodeId as string,
                workspaceName: nodeInfo.workspace as string,
                targetCoordinates: JSON.stringify(params.targetCoordinates),
                contentRepositoryId: nodeInfo.contentRepositoryId,
                force: params.force
            });
        },
        onSuccess: (response) => {
            if (response.skippedReason) {
                setResultDialog({
                    type: 'warn',
                    title: t('dialog.skipped', 'Skipped', {}, 'Sitegeist.LostInTranslation', 'Main'),
                    message: response.message,
                });
            } else if (response.stalePropertyCommandsDispatched === 0 && response.variantCommandsDispatched === 0) {
                setResultDialog({
                    type: 'warn',
                    title: t('dialog.noop', 'No Change', {}, 'Sitegeist.LostInTranslation', 'Main'),
                    message: response.message,
                });
            } else {
                setResultDialog({
                    type: 'success',
                    title: t('dialog.success', 'Translation Complete', {}, 'Sitegeist.LostInTranslation', 'Main'),
                    message: response.message,
                });
            }
        },
        onError: (error: Error) => {
            setResultDialog({
                type: 'error',
                title: t('dialog.error', 'Translation Failed', {}, 'Sitegeist.LostInTranslation', 'Main'),
                message: error.message || 'An unknown error occurred',
            });
        },
        onSettled: () => setActiveButton(null),
    });

    const closeDialog = useCallback(() => setResultDialog(null), [setResultDialog]);

    const reloadPage = useCallback(() => window.location.reload(), []);

    const options = useMemo(() => contentData?.specializations.map((spec) => {
        const label = Object.keys(spec.targetCoordinates).map((key) => {
            return `${contentData.dimensionNames[key]}: ${spec.targetCoordinates[key].toUpperCase()}`;
        }).join(' / ') + ' ' + t('view.staleNodes', '', {staleNodeCount: '' + spec.staleNodeCount}, 'Sitegeist.LostInTranslation', 'Main') + ')';
        return {
            value: JSON.stringify(spec.targetCoordinates),
            label,
        };
    }), [contentData?.specializations]);

    if (isLoading) {
        return (
            <Container>
                <LoadingContainer>
                    <Spinner/>
                    <Info>
                        {t('view.loading', '', {}, 'Sitegeist.LostInTranslation', 'Main')}
                    </Info>
                </LoadingContainer>
            </Container>
        );
    }

    if (!contentData || contentData.specializations.length === 0) {
        return null;
    }

    const currentValue = selectedTarget ?? options[0].value;
    const selectedSpec = contentData.specializations.find(
        (spec) => JSON.stringify(spec.targetCoordinates) === currentValue
    );

    return (
        <>
            <Container>
                <SelectBox
                    options={options}
                    value={currentValue}
                    onValueChange={(value: string) => setSelectedTarget(value)}
                    disabled={translateMutation.isLoading}
                />
                {selectedSpec && (
                    <ButtonsContainer>
                        <Button
                            onClick={() => {
                                setActiveButton('translate');
                                translateMutation.mutate({targetCoordinates: selectedSpec.targetCoordinates});
                            }}
                            disabled={translateMutation.isLoading || selectedSpec.staleNodeCount === 0}
                        >
                            {translateMutation.isLoading && activeButton === 'translate' ? (
                                <LoadingContainer>
                                    <Spinner/>
                                    {t('view.translating', '', {}, 'Sitegeist.LostInTranslation', 'Main')}
                                </LoadingContainer>
                            ) : (
                                t('button.translate', '', {}, 'Sitegeist.LostInTranslation', 'Main')
                            )}
                        </Button>
                        <Button onClick={() => {
                            setActiveButton('force');
                            translateMutation.mutate({
                                targetCoordinates: selectedSpec.targetCoordinates,
                                force: true
                            });
                        }}
                            disabled={translateMutation.isLoading}
                        >
                            {translateMutation.isLoading && activeButton === 'force' ? (
                                <LoadingContainer>
                                    <Spinner/>
                                    {t('view.translating', '', {}, 'Sitegeist.LostInTranslation', 'Main')}
                                </LoadingContainer>
                            ) : (
                                t('button.forceTranslate', 'Force', {}, 'Sitegeist.LostInTranslation', 'Main')
                            )}
                        </Button>
                    </ButtonsContainer>
                )}
            </Container>
            {resultDialog && (
                <Dialog
                    isOpen={true}
                    onRequestClose={closeDialog}
                    title={resultDialog.title}
                    type={resultDialog.type}
                    actions={[
                        <Button key="reload" onClick={reloadPage}>
                            {t('dialog.reload', 'Reload', {}, 'Sitegeist.LostInTranslation', 'Main')}
                        </Button>,
                        <Button key="close" onClick={closeDialog}>
                            {t('dialog.close', 'Close', {}, 'Sitegeist.LostInTranslation', 'Main')}
                        </Button>,
                    ]}
                >
                    <DialogContent>
                        {resultDialog.message}
                    </DialogContent>
                </Dialog>
            )}
        </>
    );
};
