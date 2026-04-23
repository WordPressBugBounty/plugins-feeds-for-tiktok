import { __ } from "@wordpress/i18n";
import React, {useState, Fragment} from "react";
import { CloseIcon } from "../icons";
import {Button, Modal} from '@wordpress/components';

const FauxBlocksModal = (props) => {
    const { block, removeBlock, handleInstallActivate } = props;
    const [ modalOpen, setModalOpen ] = useState( true );
	const closeModal = () => {
        setModalOpen( false );
        removeBlock();
    }

    if ( ! modalOpen ) {
		return null;
	}

    return (
        <Fragment>
            { modalOpen && (
                <Modal 
                    __experimentalHideHeader= {true}
                    onRequestClose={ closeModal }
                    className='sbi-faux-block-modal'
                >
                    <div className="sbi-modal-header">
                        <div className="sbi-modal-title-wrap">
                            <span className='sbi-modal-icon'>
                                {block?.logo}
                            </span>
                            <div className="sbi-modal-title">
                                <div className='sbi-requires'>
                                    {__('REQUIRES', 'instagram-feed')}
                                </div>
                                <div className="sbi-title">
                                    <h2>
                                        {block?.title}
                                    </h2>
                                    <span className="sbi-plugin-tag">
                                        {__('FREE', 'instagram-feed')}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div className="sbi-modal-content-wrap">
                        <div className="sbi-modal-content">
                            <p>
                                {block?.contentDesc}
                            </p>
                            <Button 
                                href={block?.downloadLink}
                                className='sbi-modal-cta-btn'
                                onClick={handleInstallActivate}
                            >
                                { block?.pluginInstalled ? __( 'Activate', 'instagram-feed' ) 
                                : __( 'Install', 'instagram-feed' ) }
                            </Button>
                        </div>
                    </div>

                    <Button 
                        icon={CloseIcon} 
                        onClick={ closeModal } 
                        className='sbi-modal-close'
                    />
                </Modal>
            )}
        </Fragment>
    )
}

export default FauxBlocksModal;