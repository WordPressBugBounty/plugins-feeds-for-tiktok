import { __ } from '@wordpress/i18n';
import { useEffect, useState } from 'react';
import { Button, Modal, Spinner } from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import { CloseIcon, PluginIcon } from './icons.js';
import Preview from './preview.js';

/**
 * Editor css.
 */
import './editor.scss';

export default function Edit(props) {
	const { attributes } = props;
	const { pluginPath, pluginName, pluginPage, logo, description, preview } = attributes;

	const { removeBlock } = useDispatch('core/block-editor');

	const [show, setShow] = useState(false);
	const handleShow = () => setShow(true);
	const handleClose = () => {
		removeBlock(props.clientId);
		setShow(false);
	};
	const goToPluginPage = () => {
		removeBlock(props.clientId);
		setShow(false);
		window.location.href = pluginPage;
	}

	const installPlugin = () => {
		const nonce = recommendedBlocksData.nonce;
		const actionUrl = recommendedBlocksData.siteUrl;
		const button = document.querySelector('.am-modal-cta-btn');
		const spinner = document.querySelector('.components-spinner');

		const data = {
			'action': 'am_recommended_block_install',
			'nonce': nonce,
			'plugin': pluginPath,
		}

		const url = new URL(actionUrl);
		url.search = new URLSearchParams(data).toString();

		spinner.style = "display: inline-block";

		fetch(url, {
			cache: 'no-cache',
			headers: {
				'user-agent': 'Recommended Block',
				'content-type': 'application/json'
			},
			method: 'POST',
		})
			.then(response => {
				if (response.ok) {
					button.innerHTML = "Installed! Go to Plugin's Page.";
					spinner.style = "display: none";

					button.addEventListener('click', function (event) {
						goToPluginPage();
					}, false);
				} else {
					button.innerHTML = "There was a problem with the plugin install";
				}
			})
	};

	useEffect(() => {
		handleShow();
	}, []);

	if (preview) {
		return (
			<Preview pluginName={pluginName} />
		);
	}

	return (
		<>
			<Modal
				__experimentalHideHeader={true}
				show={show}
				onRequestClose={handleClose}
				className='am-recommended-block-modal'
			>
				<div className="am-modal-header">
					<div className="am-modal-title-wrap">
						<span className='am-modal-icon'>
							{logo || <PluginIcon />}
						</span>
						<div className="am-modal-title">
							<div className='am-requires'>
								{__('REQUIRES', 'smashballoon')}
							</div>
							<div className="am-title">
								<h2>
									{pluginName}
								</h2>
								<span className="am-plugin-tag">
									{__('FREE', 'smashballoon')}
								</span>
							</div>
						</div>
					</div>
				</div>

				<div className="am-modal-content-wrap">
					<div className="am-modal-content">
						<p dangerouslySetInnerHTML={{ __html: description }} />
						<Button
							className='am-modal-cta-btn'
							onClick={installPlugin}
							variant="primary"
						>
							<>
								{__('Install', 'smashballoon')}
								<Spinner />
							</>
						</Button>
					</div>
				</div>

				<Button
					icon={CloseIcon}
					onClick={handleClose}
					className='am-modal-close'
				/>
			</Modal>
		</>
	);
}